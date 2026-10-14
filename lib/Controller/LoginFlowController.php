<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Miroslav Bauer <Miroslav.Bauer@cesnet.cz>
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
namespace OCA\OpenIdConnect\Controller;

use Jumbojett\OpenIDConnectClientException;
use OC\HintException;
use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCA\OpenIdConnect\ProviderRequestException;
use OCA\OpenIdConnect\Service\AccountLoginException;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;

class LoginFlowController extends Controller {
	private readonly Session $userSession;
	private readonly Logger $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UserLookupService $userLookup,
		IUserSession $userSession,
		private readonly ISession $session,
		ILogger $logger,
		private readonly Client $client,
		private readonly ICacheFactory $memCacheFactory,
		private readonly AutoProvisioningService $autoProvisioningService,
	) {
		parent::__construct($appName, $request);
		if (!$userSession instanceof Session) {
			throw new \Exception('We rely on internal implementation!');
		}

		$this->userSession = $userSession;
		$this->logger = new Logger($logger);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @CORS
	 */
	public function config(): JSONResponse {
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			return new JSONResponse([]);
		}
		try {
			$wellKnownData = $openid->getWellKnownConfig();
		} catch (OpenIDConnectClientException $ex) {
			// Aufrufer sind die Clients über /.well-known/openid-configuration: Sie
			// werten nur HTTP 200 aus und fallen sonst auf ihre Vorgaben zurück. 503
			// sagt, dass es am Anbieter liegt; nichts Internes in die Antwort.
			if (!$ex instanceof ProviderRequestException) {
				// ProviderRequestException hat Client::fetchURL() schon protokolliert
				$this->logger->warning('OpenID Connect discovery document not available: ' . $ex->getMessage());
			}
			return new JSONResponse([
				'error' => 'temporarily_unavailable',
				'error_description' => 'The OpenID Connect provider is not available.',
			], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return new JSONResponse($wellKnownData);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 *
	 * @throws HintException
	 * @throws LoginException
	 */
	public function login(): Response {
		$this->logger->debug('Entering LoginFlowController::login');
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			throw new HintException('Configuration issue in openidconnect app');
		}
		try {
			$this->logger->debug('Before openid->authenticate');
			// Ein neuer Anmeldevorgang (noch keine Antwort des Anbieters)
			// beginnt ohne das Ziel eines früheren, abgebrochenen.
			if ($this->request->getParam('code') === null && $this->request->getParam('error') === null) {
				$openid->clearRedirectUrl();
			}
			$openid->storeRedirectUrl($this->request->getParam('redirect_url'));
			$openid->authenticate();
		} catch (OpenIDConnectClientException $ex) {
			throw $this->signInFailed($ex);
		}
		// Never log raw tokens — a debug log otherwise becomes a store of live
		// bearer/refresh credentials. Log only their presence.
		$debugInfo = \json_encode([
			'has_access_token' => (string)$openid->getAccessToken() !== '',
			'has_refresh_token' => (string)$openid->getRefreshToken() !== '',
			'has_id_token' => (string)$openid->getIdToken() !== '',
		], JSON_PRETTY_PRINT);
		$this->logger->debug('LoginFlowController::login : token presence: ' . $debugInfo);

		try {
			$userInfo = $openid->getUserInfo();
		} catch (OpenIDConnectClientException $ex) {
			throw $this->signInFailed($ex);
		}
		// Log only the claim names, not their (personally identifiable) values.
		$this->logger->debug('User info claims: ' . \json_encode($userInfo ? \array_keys((array)$userInfo) : []));
		if (!$userInfo) {
			throw new LoginException('No user information available.');
		}
		try {
			$user = $this->userLookup->lookupUser($userInfo);
		} catch (LoginException $ex) {
			// Modified by BW-Tech GmbH on 2026-09-17: the core printed the raw
			// English exception text on a page without a way back ("User with
			// x is not known."). Now a translated page with a link to the login
			// form. The exception text carries the identity (e-mail, user id) -
			// like the claim values above it stays out of the log; the log gets
			// the cause and configuration names instead.
			$ursache = $ex instanceof AccountLoginException ? $ex->logText() : 'no usable account for the identity';
			$this->logger->warning('OpenID::login: ' . $ursache);
			$openid->clearRedirectUrl();
			return $this->loginFailedResponse($openid);
		}

		if ($this->autoProvisioningService->autoUpdateEnabled()) {
			$this->autoProvisioningService->updateAccountInfo($user, $userInfo);
		}

		// trigger login process
		if ($this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID())
			&& $this->userSession->loginUser($user, null, OpenIdConnectAuthModule::class)) {
			$this->session->set('oca.openid-connect.id-token', $openid->getIdToken());
			$this->session->set('oca.openid-connect.access-token', $openid->getAccessToken());
			$this->session->set('oca.openid-connect.refresh-token', $openid->getRefreshToken());

			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			if (isset($openid->getIdTokenPayload()->sid)) {
				/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
				$sid = $openid->getIdTokenPayload()->sid;
				$this->session->set('oca.openid-connect.session-id', $sid);
				$this->memCacheFactory
					->create('oca.openid-connect.sessions')
					->set($sid, true);
			} else {
				$this->logger->debug('Id token holds no sid');
			}
			$response = new RedirectResponse($this->getDefaultUrl());
			$openIdConfig = $openid->getOpenIdConfig();
			$cookieName = $openIdConfig['ocis-routing-policy-cookie'] ?? 'owncloud-selector';
			$cookieDirectives = $openIdConfig['ocis-routing-policy-cookie-directives'] ?? 'path=/;';
			// openidconnect 2.1.x bis 2.2.x lasen den Schlüssel mit Tippfehler
			// ("poclicy", upstream #289 erst in 2.3.0 behoben). Eine übernommene
			// Konfiguration, die ihn so gesetzt hat, gilt weiter; die richtige
			// Schreibweise hat Vorrang.
			$attribute = $openIdConfig['ocis-routing-policy-claim']
				?? $openIdConfig['ocis-routing-poclicy-claim']
				?? 'ocis.routing.policy';
			if (\property_exists($userInfo, $attribute)) {
				$response->addHeader('Set-Cookie', "$cookieName={$userInfo->$attribute};$cookieDirectives");
			}
			return $response;
		}
		$this->logger->error("Unable to login {$user->getUID()}");
		return new RedirectResponse('/');
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 */
	public function logout(?string $iss = null, ?string $sid = null): Response {
		// fail fast if not configured
		$openIdConfig = $this->client->getOpenIdConfig();
		if ($openIdConfig === null) {
			$this->logger->warning('OpenID::logout: OpenID is not properly configured');
			return new Response();
		}
		// Front-channel logout is only legitimate when the IdP supplies iss + sid.
		// A bare cookie-only request (e.g. a CSRF <img src=".../logout">) carries
		// neither, and must NEVER terminate the user's active session. Validate the
		// parameters BEFORE touching the session — the original order logged the user
		// out first and validated afterwards, so any third-party page could force a
		// logout.
		if ($iss === null || $sid === null) {
			$this->logger->warning("OpenID::logout: missing parameters: iss={$iss} and sid={$sid}");
			return new Response();
		}
		if (isset($openIdConfig['provider-url'])) {
			if (!Util::isSameDomain($openIdConfig['provider-url'], $iss)) {
				$this->logger->warning("OpenID::logout: iss {$iss} !== provider-url {$openIdConfig['provider-url']}");
				return new Response();
			}
		}

		// Only terminate the active session when the sid actually matches the one
		// stored for this session at login. Without this an attacker who knows the
		// (public) issuer URL could still force a logout with an arbitrary sid.
		if ($this->userSession->isLoggedIn()) {
			$sessionSid = $this->session->get('oca.openid-connect.session-id');
			if ($sessionSid !== null && \hash_equals((string)$sessionSid, (string)$sid)) {
				$user = $this->userSession->getUser() ? $this->userSession->getUser()->getUID() : '-unknown-user-';
				$this->logger->debug("OpenID::logout: sid match -> performing logout for $user");
				$this->userSession->logout();
			} else {
				$this->logger->warning('OpenID::logout: sid does not match the active session; not terminating it');
			}
		}

		$this->memCacheFactory
			->create('oca.openid-connect.sessions')
			->remove($sid);

		$this->logger->warning("OpenID::logout: session terminated: iss={$iss} and sid={$sid}");

		$resp = new Response();
		$resp->setHeaders([
			'Cache-Control' => 'no-cache, no-store',
			'Pragma' => 'no-cache',
		]);
		return $resp;
	}

	/**
	 * Die Fehlerseite für einen gescheiterten Anmeldeschritt beim Anbieter
	 * (HintException, im Kern HTTP 503).
	 */
	private function signInFailed(OpenIDConnectClientException $ex): HintException {
		if ($ex instanceof ProviderRequestException) {
			// Ursache samt Adresse hat Client::fetchURL() schon protokolliert; die
			// Seite nennt nichts davon.
			return new HintException(
				'Sign-in with the identity provider failed',
				'The identity provider could not be reached or rejected the request. Please try again later or contact your administrator.',
				0,
				$ex
			);
		}
		$this->logger->logException($ex);
		return new HintException('Error in OpenIdConnect:' . $ex->getMessage());
	}

	/**
	 * Ziel nach der Anmeldung: die vor der Anmeldung angeforderte Seite, sonst
	 * die Startseite.
	 *
	 * Modified by BW-Tech GmbH on 2026-09-17: the requested page is evaluated
	 * here. The redesign core (owncloud.online 11.1) no longer reads
	 * $_REQUEST['redirect_url'] in OC_Util::getDefaultPageUrl() - only the
	 * core login controller does. Setting it and asking for the default page
	 * therefore sent every OpenID Connect login to the start page, including
	 * logins that began on an OAuth2 authorization of a desktop or mobile
	 * client. Same rule as the core login: absolute URL on this server, targets
	 * containing '@' are dropped (?redirect_url=:user@evil.example). Targets
	 * with control characters are dropped as well: a decoded line break made
	 * PHP refuse the Location header, and the user stood on an empty page.
	 * The stored target is used once.
	 */
	protected function getDefaultUrl(): string {
		$openid = $this->getOpenIdConnectClient();
		$redirectUrl = $openid ? $openid->readRedirectUrl() : null;
		if ($openid) {
			$openid->clearRedirectUrl();
		}
		if (\is_string($redirectUrl) && $redirectUrl !== '') {
			$location = \OC::$server->getURLGenerator()->getAbsoluteURL(\urldecode($redirectUrl));
			if (\strpos($location, '@') === false && !\preg_match('/[\x00-\x1f\x7f]/', $location)) {
				return $location;
			}
			$this->logger->warning('OpenID::login: redirect target dropped, it points away from this server or contains control characters');
		}

		return \call_user_func(['OC_Util', 'getDefaultPageUrl']);
	}

	/**
	 * Seite "Anmeldung hat nicht geklappt" in der Gastansicht, Status 403.
	 *
	 * Ohne Anbieternamen: das Feld loginButtonName ist die Beschriftung des
	 * Knopfs ("Login via OpenID Connect", "Mit Firmenkonto anmelden") und
	 * ergab im Satz Unsinn.
	 *
	 * Der Rückweg zur Anmeldung fehlt, wenn die Anmeldeseite sofort zum
	 * Anbieter weiterleitet - dort ginge es nur im Kreis. Solange die Sitzung
	 * beim Anbieter besteht, meldet er dasselbe Konto sofort wieder an; nennt
	 * er eine Abmeldeadresse, bietet die Seite die Abmeldung dort an.
	 */
	private function loginFailedResponse(Client $openid): TemplateResponse {
		$openIdConfig = $openid->getOpenIdConfig() ?? [];
		$autoRedirect = (bool)($openIdConfig['autoRedirectOnLoginPage'] ?? false);
		$providerLogoutUrl = '';
		$endpoint = $openid->getEndSessionEndpoint();
		if ($endpoint !== null) {
			$params = ['client_id' => (string)($openIdConfig['client-id'] ?? '')];
			$nachAbmeldung = (string)($openIdConfig['post_logout_redirect_uri'] ?? '');
			if ($nachAbmeldung !== '') {
				$params['post_logout_redirect_uri'] = $nachAbmeldung;
			}
			$providerLogoutUrl = $endpoint . (\strpos($endpoint, '?') === false ? '?' : '&') . \http_build_query($params);
		}
		$response = new TemplateResponse($this->appName, 'login-failed', [
			'loginUrl' => $autoRedirect ? '' : \OC::$server->getURLGenerator()->linkToRoute('core.login.showLoginForm'),
			'providerLogoutUrl' => $providerLogoutUrl,
		], 'guest');
		$response->setStatus(Http::STATUS_FORBIDDEN);
		return $response;
	}

	private function getOpenIdConnectClient(): ?Client {
		if ($this->client->getOpenIdConfig() === null) {
			return null;
		}
		return $this->client;
	}
}
