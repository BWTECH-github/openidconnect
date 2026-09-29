<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Miroslav Bauer <Miroslav.Bauer@cesnet.cz>
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2022, ownCloud GmbH
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OpenIdConnect;

require_once __DIR__ . '/bootstrap.php';
loadComposerDependencies();
assertComposerDependencies();

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\ISession;
use OCP\IURLGenerator;
use Psr\Http\Client\NetworkExceptionInterface;

class Client extends OpenIDConnectClient {
	/**
	 * Claims, mit denen ein Anbieter den Client benennt, für den er ein Token
	 * ausgestellt hat - in dieser Rangfolge: "azp" (OpenID Connect Core 1.0 §2),
	 * "appid" (Entra ID v1.0, AD FS), "client_id" (RFC 7662 §2.2). Wie upstream
	 * owncloud/openidconnect 2.3.5/2.4.2 (#374).
	 */
	private const CLIENT_NAMING_CLAIMS = ['azp', 'appid', 'client_id'];

	private ?object $wellKnownConfig = null;
	private bool $audienceFallbackReported = false;
	private bool $unusableAudienceReported = false;
	/**
	 * Der Netzwerkfehler, mit dem der Anbieter in dieser Anfrage schon einmal
	 * nicht erreichbar war - siehe fetchURL().
	 */
	private ?\Throwable $unreachableCause = null;

	/**
	 * @throws \JsonException
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IURLGenerator $generator,
		private readonly ISession $session,
		private readonly ILogger $logger,
		private readonly IClientService $clientService,
	) {
		$openIdConfig = $this->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		parent::__construct(
			$openIdConfig['provider-url'],
			$openIdConfig['client-id'],
			$openIdConfig['client-secret']
		);
		$scopes = $openIdConfig['scopes'] ?? ['openid', 'profile', 'email'];
		$this->addScope($scopes);

		$insecure = $openIdConfig['insecure'] ?? false;
		if ($insecure) {
			$this->setVerifyHost(false);
			$this->setVerifyPeer(false);
		}
		// set config parameters in case well known is not supported
		if (isset($openIdConfig['provider-params'])) {
			$this->providerConfigParam($openIdConfig['provider-params']);
		}
		// set additional auth parameters
		if (isset($openIdConfig['auth-params'])) {
			$this->addAuthParam($openIdConfig['auth-params']);
		}
	}

	/**
	 * @return mixed
	 */
	public function getOpenIdConfig() {
		$configRaw = $this->config->getAppValue(Application::APPID, 'openid-connect', null);
		if ($configRaw) {
			$config = \json_decode($configRaw, true);
			if (\json_last_error() === JSON_ERROR_NONE && \is_array($config)) {
				return $config;
			}

			$this->logger->error(
				'Loaded config from DB is not valid (malformed JSON); JSON Last Error: ' . \json_last_error(),
				['app' => Application::APPID]
			);
		}

		$config = $this->config->getSystemValue('openid-connect', null);
		return \is_array($config) ? $config : null;
	}

	public function getAutoProvisionConfig(): array {
		return $this->getOpenIdConfig()['auto-provision'] ?? [];
	}

	public function getAutoUpdateConfig(): array {
		return $this->getAutoProvisionConfig()['update'] ?? [];
	}

	/**
	 * Das Discovery-Dokument des Anbieters, wie es /apps/openidconnect/config
	 * weiterreicht.
	 *
	 * @throws ProviderRequestException Anbieter nicht erreichbar, Fehlerstatus
	 *                                  oder kein JSON-Objekt als Antwort
	 * @throws OpenIDConnectClientException keine provider-url konfiguriert
	 */
	public function getWellKnownConfig(): object {
		if ($this->wellKnownConfig === null) {
			$well_known_config_url = \rtrim($this->getProviderURL(), '/') . '/.well-known/openid-configuration';
			$body = $this->fetchURL($well_known_config_url);
			try {
				$document = \json_decode((string)$body, false, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				$document = null;
			}
			// Eine Fehlerseite eines Proxys, ein leerer Body oder JSON ohne Objekt
			// ist kein Discovery-Dokument - und darf nicht als solches durchgereicht
			// werden.
			if (!\is_object($document)) {
				$this->logger->warning(
					"OpenID Connect provider returned no usable discovery document (no JSON object): $well_known_config_url",
					['app' => 'OpenID']
				);
				throw new ProviderRequestException();
			}
			$this->wellKnownConfig = $document;
		}
		return $this->wellKnownConfig;
	}

	public function mode() {
		return $this->getOpenIdConfig()['mode'] ?? 'userid';
	}

	#[\Override]
	public function getAccessTokenPayload(): ?object {
		if ($this->accessToken === '') {
			return null;
		}
		$parts = explode('.', $this->accessToken);
		if (!isset($parts[1])) {
			return null;
		}

		return parent::getAccessTokenPayload();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function verifyToken(string $token) {
		$config = $this->getOpenIdConfig();
		$this->setAccessToken($token);
		$payload = $this->getAccessTokenPayload();
		if ($payload) {
			if (!$this->verifyJWTsignature($token)) {
				// Do not log the raw token on failure.
				$this->logger->error('Token cannot be verified');
				throw new OpenIDConnectClientException('Token cannot be verified.');
			}
			// A signature alone does not say the token was issued for *this* client:
			// a correctly signed, unexpired token minted by the same issuer for a
			// different client would otherwise authenticate as the matching account,
			// over bearer auth or the browser session (OC10-115).
			if ($this->audienceCheckEnabled($config)) {
				$clientId = $config['client-id'] ?? $this->getClientID();
				$this->verifyAudience($payload, $this->getExpectedAudiences($config, $clientId), isset($config['audience']), $clientId);
			}
			// Log only the expiry, never the decoded claims (they carry PII).
			$this->logger->debug('Access token verified (exp: ' . ($payload->exp ?? 'n/a') . ')');
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			return $payload->exp;
		}

		# use token introspection to verify the token
		$introspectionClientId = $config['token-introspection-endpoint-client-id'] ?? null;
		$introspectionClientSecret = $config['token-introspection-endpoint-client-secret'] ?? null;
		$tokenExchangeMode = $config['exchange-token-mode-before-introspection'] ?? null;

		if ($tokenExchangeMode) {
			$token = $tokenExchangeMode === 'refresh-token' ? $this->session->get('oca.openid-connect.refresh-token') : $this->session->get('oca.openid-connect.access-token');
			$this->logger->debug("Starting token-exchange to verify session with subject_token mode: $tokenExchangeMode");

			$token = $this->exchangeToken($token, $tokenExchangeMode);
		}

		$introData = $this->introspectToken($token, '', $introspectionClientId, $introspectionClientSecret);
		if ($introData === null) {
			return null;
		}
		$this->logger->debug('Introspection info: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
		if (\property_exists($introData, 'error')) {
			$this->logger->error('Token introspection failed: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
			throw new OpenIDConnectClientException("Verifying token failed: {$introData->error}");
		}
		if (!$introData->active) {
			$this->logger->error('Token (as per introspection) is inactive: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
			throw new OpenIDConnectClientException('Token (as per introspection) is inactive');
		}
		// Same gap as OC10-115, on the other branch: an opaque token minted by the
		// same issuer for a different client is reported active here, so "active"
		// alone authenticated its subject — unauthenticated, via the Authorization
		// header (OC10-147).
		//
		// RFC 7662 makes "aud" optional and providers commonly use it for the
		// resource server rather than the client (Keycloak emits "account", Okta
		// "api://default"). Unless "audience" is configured, a client-naming claim
		// - "client_id" per RFC 7662 §2.2 among them - is therefore accepted
		// instead, see verifyAudience(). Still fails closed: if nothing names us,
		// verifyAudience() throws.
		if ($this->audienceCheckEnabled($config)) {
			$clientId = $config['client-id'] ?? $this->getClientID();
			$this->verifyAudience($introData, $this->getExpectedAudiences($config, $clientId), isset($config['audience']), $clientId);
		}
		return $introData->exp;
	}

	/**
	 * Whether the audience of an access token is bound to the configured
	 * client-id. On by default — leaving it off is what OC10-115 and OC10-147
	 * are. `token-aud-check => false` is the escape hatch for an identity
	 * provider that can neither be fixed nor name this relying party, and it
	 * reopens both findings; it exists so that such an installation degrades to
	 * the old behaviour instead of losing every login on upgrade.
	 *
	 * @param array $config
	 */
	private function audienceCheckEnabled(array $config): bool {
		return ($config['token-aud-check'] ?? true) !== false;
	}

	/**
	 * Die Audience-Werte, die ein Zugriffstoken tragen muss.
	 *
	 * Standard ist die client-id. Ein Zugriffstoken nennt in "aud" nach RFC 9068
	 * §3 aber den Ressourcenserver, nicht den Client; Anbieter, die das so
	 * halten, brauchen den Schlüssel "audience", der die client-id ersetzt (nicht
	 * ergänzt). Den Schlüssel gibt es seit upstream openidconnect 2.3.5 für
	 * ownCloud 10 und 2.4.2 für ownCloud 11 (#374) - eine übernommene
	 * Konfiguration, die ihn setzt, muss hier genauso wirken wie dort.
	 *
	 * @param array|null $config die openid-connect-Konfiguration
	 * @param string|null $clientId die konfigurierte client-id
	 * @return string[] nur nicht-leere Zeichenketten; leer heißt, dass kein
	 *                  Token angenommen wird - so muss ein kaputter Wert wirken
	 */
	private function getExpectedAudiences(?array $config, ?string $clientId): array {
		$configured = $config['audience'] ?? $clientId;
		if (!\is_array($configured)) {
			$configured = [$configured];
		}
		// Alles andere als eine brauchbare Zeichenkette ist ein Konfigurationsfehler
		// und darf die Prüfung nicht aufweichen - ein leerer String würde sonst auf
		// ein Token mit leerem "aud" passen.
		$expected = $this->usableAudienceStrings($configured);
		if (isset($config['audience'])
			&& ($expected === [] || \count($expected) !== \count($configured))
			&& !$this->unusableAudienceReported
		) {
			$this->unusableAudienceReported = true;
			$this->logger->warning(\sprintf(
				$expected === []
					? 'No usable "audience" in the openid-connect config, only non-empty strings are accepted, so every access token will be rejected: %s'
					: 'Ignoring unusable "audience" values in the openid-connect config, only non-empty strings are accepted: %s',
				\json_encode($config['audience'], JSON_UNESCAPED_SLASHES)
			));
		}
		return $expected;
	}

	/**
	 * Stellt sicher, dass das Token für diese Instanz ausgestellt wurde: Eine der
	 * erwarteten Audiences muss in "aud" stehen (String oder Liste nach RFC 7519
	 * und RFC 7662). Ohne diese Prüfung würde ein Token angenommen, das derselbe
	 * Anbieter für einen anderen Client ausgestellt hat (OC10-115, OC10-147).
	 *
	 * Ist "audience" nicht gesetzt, genügt stattdessen ein Claim, der diese
	 * Instanz als den Client benennt, siehe tokenNamesThisClient(). Das ist der
	 * Weg, auf dem Keycloak (kein oder "account" in "aud"), Entra ID und AD FS
	 * weiter funktionieren - und damit jede Konfiguration, die von einer
	 * Vorgängerversion ohne Audience-Prüfung übernommen wurde. Wie upstream #374.
	 *
	 * @param object $payload der Payload des Zugriffstokens oder die
	 *                        Introspection-Antwort
	 * @param string[] $expectedAudiences aus getExpectedAudiences()
	 * @param bool $audienceIsAuthoritative ob "audience" gesetzt ist; dann zählt
	 *                                      nur "aud"
	 * @param string|null $clientId die konfigurierte client-id
	 * @throws OpenIDConnectClientException wenn nichts diese Instanz benennt
	 */
	private function verifyAudience(
		object $payload,
		array $expectedAudiences,
		bool $audienceIsAuthoritative,
		?string $clientId
	): void {
		$audience = $payload->aud ?? null;
		$audiences = \is_array($audience) ? $audience : [$audience];
		foreach ($expectedAudiences as $expected) {
			// strikt und bewusst nicht array_intersect(): das vergleicht lose
			if (\in_array($expected, $audiences, true)) {
				return;
			}
		}
		if (!$audienceIsAuthoritative) {
			$this->reportClientClaimMismatch($payload, $clientId);
			$claim = $this->tokenNamesThisClient($payload, $clientId);
			if ($claim !== null) {
				// nur erwähnenswert, wenn "aud" etwas trägt, das man als
				// "audience" eintragen könnte
				if ($this->usableAudienceStrings($audiences) !== []) {
					$this->reportAudienceFallbackOnce($claim, $audience);
				}
				return;
			}
		}
		// "aud" ist Claim-Inhalt, kein Geheimnis - ihn zu nennen macht einen falsch
		// eingerichteten Anbieter erst diagnostizierbar.
		$this->logger->error(\sprintf(
			'Token audience does not match the expected audience: token "aud" is %s, expected one of %s',
			\json_encode($audience, JSON_UNESCAPED_SLASHES),
			\json_encode($expectedAudiences, JSON_UNESCAPED_SLASHES)
		));
		throw new OpenIDConnectClientException('Token audience does not match the expected audience');
	}

	/**
	 * Welcher Claim diese Instanz als den Client benennt, für den das Token
	 * ausgestellt wurde - oder null.
	 *
	 * Es entscheidet der ranghöchste Claim, der überhaupt einen Wert trägt, nicht
	 * der erste, der zufällig passt: Sonst könnte ein anderer Client desselben
	 * Anbieters ein ehrliches "azp" mit seinem eigenen Namen und daneben ein
	 * festverdrahtetes "client_id" mit unserem vorlegen.
	 *
	 * @param object $payload
	 * @param string|null $clientId die konfigurierte client-id
	 * @return string|null
	 */
	private function tokenNamesThisClient(object $payload, ?string $clientId): ?string {
		if ($clientId === null || $clientId === '') {
			return null;
		}
		$claim = $this->decidingClientClaim($payload);
		if ($claim === null) {
			return null;
		}
		// strikt: eine numerische client-id darf nicht auf einen Claim anderen Typs passen
		return $clientId === $payload->$claim ? $claim : null;
	}

	/**
	 * Der ranghöchste client-benennende Claim, der einen Wert trägt (ein auf
	 * JSON null gesetzter Claim benennt niemanden und entscheidet nicht).
	 *
	 * @param object $payload
	 * @return string|null
	 */
	private function decidingClientClaim(object $payload): ?string {
		foreach (self::CLIENT_NAMING_CLAIMS as $claim) {
			if (isset($payload->$claim)) {
				return $claim;
			}
		}
		return null;
	}

	/**
	 * Sagt, wenn ein client-benennender Claim die Ablehnung entschieden hat - die
	 * anschließende Meldung nennt nur die Audience und würde sonst auf eine
	 * falsche Fährte führen.
	 *
	 * @param object $payload
	 * @param string|null $clientId
	 */
	private function reportClientClaimMismatch(object $payload, ?string $clientId): void {
		if ($clientId === null || $clientId === '') {
			return;
		}
		$claim = $this->decidingClientClaim($payload);
		if ($claim !== null && $clientId !== $payload->$claim) {
			$this->logger->error(\sprintf(
				'Token was issued to another client: "%s" is %s, this relying party is %s. '
				. 'Lower-precedence claims are deliberately not consulted once a more '
				. 'authoritative one names somebody else.',
				$claim,
				\json_encode($payload->$claim, JSON_UNESCAPED_SLASHES),
				\json_encode($clientId, JSON_UNESCAPED_SLASHES)
			));
		}
	}

	/**
	 * Die als Audience brauchbaren Werte, also nicht-leere Zeichenketten - für
	 * beide Seiten des Vergleichs gleich.
	 *
	 * @param array $audiences
	 * @return string[]
	 */
	private function usableAudienceStrings(array $audiences): array {
		return \array_values(\array_filter($audiences, static function ($audience) {
			return \is_string($audience) && $audience !== '';
		}));
	}

	/**
	 * Einmal je Instanz auf Stufe info: Das Token wurde über einen
	 * client-benennenden Claim angenommen, nicht über "aud". Info statt warning,
	 * weil das bei jedem Anbieter dieser Art der normale Weg ist.
	 *
	 * @param string $claim
	 * @param mixed $audience
	 */
	private function reportAudienceFallbackOnce(string $claim, $audience): void {
		if ($this->audienceFallbackReported) {
			return;
		}
		$this->audienceFallbackReported = true;
		$this->logger->info(\sprintf(
			'Access token "aud" does not name this relying party, accepted because "%s" matches the configured client-id. '
			. 'To have the audience enforced, set the openid-connect "audience" config key to what the provider sends '
			. 'in "aud" - but only if that value is one only this instance can be issued a token for, since a shared or '
			. 'tenant-wide resource identifier would accept tokens issued to other clients of the same provider: %s',
			$claim,
			\json_encode($audience, JSON_UNESCAPED_SLASHES)
		));
	}

	#[\Override]
	public function introspectToken($token, $token_type_hint = '', $clientId = null, $clientSecret = null) {
		try {
			# test if introspection is possible ...
			$this->getProviderConfigValue('introspection_endpoint');
		} catch (ProviderRequestException $ex) {
			// Den Anbieter nicht fragen zu können heißt nicht, dass er keinen
			// Introspection-Endpunkt hat: null führte auf den Userinfo-Weg, der
			// ohne Audience-Prüfung anmeldet.
			throw $ex;
		} catch (OpenIDConnectClientException) {
			return null;
		}

		return parent::introspectToken($token, $token_type_hint, $clientId, $clientSecret);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function getUserInfo() {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['use-access-token-payload-for-user-info']) && $openIdConfig['use-access-token-payload-for-user-info']) {
			if ($payload = $this->getAccessTokenPayload()) {
				return $payload;
			}
		}

		if (isset($openIdConfig['use-access-token-introspection-for-user-info']) && $openIdConfig['use-access-token-introspection-for-user-info']) {
			$introspectionClientId = $openIdConfig['token-introspection-endpoint-client-id'] ?? null;
			$introspectionClientSecret = $openIdConfig['token-introspection-endpoint-client-secret'] ?? null;
			$accessToken = $this->getAccessToken();
			if (isset($openIdConfig['exchange-token-mode-before-introspection'])) {
				$mode = $openIdConfig['exchange-token-mode-before-introspection'];
				$token = $mode === 'refresh-token' ? $this->getRefreshToken() : $this->getAccessToken();
				$this->logger->debug("Starting token-exchange to get user_info with subject_token mode: $mode");
				$accessToken = $this->exchangeToken($token, $mode);
			}

			return $this->introspectToken($accessToken, '', $introspectionClientId, $introspectionClientSecret);
		}

		return $this->requestUserInfo();
	}

	public function getIdentityClaim() {
		return $this->getOpenIdConfig()['search-attribute'] ?? 'email';
	}

	public function getEmailClaim(): ?string {
		return $this->getAutoProvisionConfig()['email-claim'] ?? null;
	}

	public function getDisplayNameClaim(): ?string {
		return $this->getAutoProvisionConfig()['display-name-claim'] ?? null;
	}

	public function getPictureClaim(): ?string {
		return $this->getAutoProvisionConfig()['picture-claim'] ?? null;
	}

	public function getUserEmail($userInfo): ?string {
		$email = $this->mode() === 'email' ? $userInfo->{$this->getIdentityClaim()} : null;
		$emailClaim = $this->getEmailClaim();
		if (!$email && $emailClaim) {
			return $userInfo->$emailClaim;
		}
		return $email;
	}

	public function getUserDisplayName($userInfo): ?string {
		$displayNameClaim = $this->getDisplayNameClaim();
		if ($displayNameClaim) {
			return $userInfo->$displayNameClaim;
		}
		return null;
	}

	public function getUserPicture($userInfo): ?string {
		$pictureClaim = $this->getPictureClaim();
		if ($pictureClaim) {
			return $userInfo->$pictureClaim;
		}
		return null;
	}

	/**
	 * Perform a RFC8693 Token Exchange.
	 * https://datatracker.ietf.org/doc/html/rfc8693
	 *
	 * @param string $tokenType Type of the token to exchange 'refresh-token' or 'access-token'
	 * @return string Access Token
	 * @throws OpenIDConnectClientException
	 */
	public function exchangeToken(string $subjectToken, string $tokenType): string {
		$subjectTokenType = $tokenType === 'refresh-token' ? 'urn:ietf:params:oauth:token-type:refresh_token' : 'urn:ietf:params:oauth:token-type:access_token';
		$exchangeResponse = $this->requestTokenExchange($subjectToken, $subjectTokenType, $this->getClientID());

		if (isset($exchangeResponse->error)) {
			if (isset($exchangeResponse->error_description)) {
				throw new OpenIDConnectClientException('TokenExchange response: ' . $exchangeResponse->error_description);
			}
			throw new OpenIDConnectClientException('TokenExchange response: ' . $exchangeResponse->error);
		}

		return $exchangeResponse->access_token;
	}

	public function storeRedirectUrl(?string $redirectUrl): void {
		if ($redirectUrl) {
			$this->setSessionKey('openid_connect_redirect_url', $redirectUrl);
		}
	}

	public function readRedirectUrl(): ?string {
		return $this->getSessionKey('openid_connect_redirect_url');
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	protected function startSession() {
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	protected function setSessionKey($key, $value) {
		$this->session->set($key, $value);
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	protected function getSessionKey($key) {
		return $this->session->get($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	protected function unsetSessionKey($key) {
		$this->session->remove($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	protected function commitSession() {
	}

	/**
	 * Fragt den Anbieter über den HTTP-Client des Kerns.
	 *
	 * Hält den Vertrag der Bibliothek ein, deren fetchURL() hier ersetzt wird:
	 * Scheitert die Anfrage, kommt eine OpenIDConnectClientException - nie die
	 * rohe Guzzle-Ausnahme, an der jeder catch-Block der App vorbeilief und die
	 * als HTTP 500 endete (Discovery, JWKS, Userinfo, Token, Introspection).
	 * Die Ursache wird genau hier einmal auf Stufe warning protokolliert; die
	 * Ausnahme selbst nennt keine Adresse, damit sie in keiner Antwort landet.
	 *
	 * War der Anbieter in dieser Anfrage schon einmal nicht erreichbar, wird
	 * nicht erneut gewartet: Jeder weitere Versuch (etwa Widerruf und Abmeldung
	 * nach einer gescheiterten Prüfung) kostete sonst noch einmal bis zum
	 * Timeout. Eine Fehlerantwort (4xx/5xx) gilt nicht als Ausfall.
	 *
	 * @throws ProviderRequestException
	 */
	#[\Override]
	protected function fetchURL($url, $post_body = null, $headers = []) {
		if ($this->unreachableCause !== null) {
			$this->logger->debug("Not fetching $url: the provider was unreachable earlier in this request");
			throw new ProviderRequestException($this->unreachableCause);
		}
		$this->logger->debug("Fetching URL: $url");

		$parsedHeaders = [];
		foreach ($headers as $header) {
			$sHeader = explode(':', $header, 2);
			if (\count($sHeader) === 2) {
				$parsedHeaders[trim($sHeader[0])] = trim($sHeader[1]);
			}
		}

		$params = [
			'headers' => $parsedHeaders,
			// Grenze der Bibliothek (CURLOPT_TIMEOUT, Vorgabe 60 s). Ohne sie hängt
			// jede Anfrage an einem Anbieter, der Pakete verwirft, bis zum
			// Verbindungs-Timeout des Systems und hält so lange einen PHP-Prozess.
			'timeout' => $this->getTimeout(),
		];

		try {
			$client = $this->clientService->newClient();
			if ($post_body === null) {
				$response = $client->get($url, $params);
				return $this->processResponseAndGetBody($response);
			}

			// Determine if this is a JSON payload and add the appropriate content type
			$json_post_body = \json_decode($post_body);
			if (\is_object($json_post_body)) {
				$params['headers']['Content-Type'] = 'application/json';
				$params['json'] = $json_post_body;
			} else {
				$params['form_params'] = [];
				\parse_str($post_body, $params['form_params']);
			}

			return $this->processResponseAndGetBody($client->post($url, $params));
		} catch (\Exception $ex) {
			// keine Antwort erhalten (DNS, Verbindung, TLS-Aufbau, Timeout)
			if ($ex instanceof NetworkExceptionInterface) {
				$this->unreachableCause = $ex;
			}
			// Keine Kopfzeilen und keinen Request-Body protokollieren: Sie tragen
			// Token und Geheimnisse.
			$this->logger->warning(
				\sprintf(
					'OpenID Connect provider request failed: %s %s: %s: %s',
					$post_body === null ? 'GET' : 'POST',
					$url,
					\get_class($ex),
					$ex->getMessage()
				),
				['app' => 'OpenID']
			);
			throw new ProviderRequestException($ex);
		}
	}

	private function processResponseAndGetBody($response) {
		$this->responseCode = $response->getStatusCode();
		// we can't set the content type for now: the attribute is private
		//$this->responseContentType = $response->getHeader('Content-Type');
		return $response->getBody();
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	public function getCodeChallengeMethod() {
		return 'S256';
	}

	#[\Override]
	protected function verifyJWKHeader($jwk) {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['jwt-self-signed-jwk-header-supported']) && $openIdConfig['jwt-self-signed-jwk-header-supported']) {
			return;
		}
		throw new OpenIDConnectClientException('Self signed JWK header is not valid');
	}

	/**
	 * @codeCoverageIgnore
	 *
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	#[\Override]
	public function authenticate() : bool {
		$redirectUrl = $this->generator->linkToRouteAbsolute('openidconnect.loginFlow.login');

		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['redirect-url'])) {
			$redirectUrl = $openIdConfig['redirect-url'];
		}

		$this->setRedirectURL($redirectUrl);
		return parent::authenticate();
	}
}
