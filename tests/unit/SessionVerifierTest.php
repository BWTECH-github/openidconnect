<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
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

namespace OCA\OpenIdConnect\Tests\Unit;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use Jumbojett\OpenIDConnectClientException;
use OC\HintException;
use OC\Http\Client\ClientService;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCA\OpenIdConnect\SessionVerifier;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

class SessionVerifierTest extends TestCase {
	/**
	 * @var SessionVerifier
	 */
	private $sessionVerifier;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $cacheFactory;
	/**
	 * @var MockObject | Client
	 */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		$dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(Logger::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);

		$config = $this->createMock(IConfig::class);
		$generator = $this->createMock(IURLGenerator::class);
		$clientService = $this->createMock(ClientService::class);
		$this->client = $this->getMockBuilder(Client::class)
			->onlyMethods(['refreshToken', 'signOut', 'getOpenIdConfig', 'verifyJWTsignature', 'getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->setConstructorArgs([$config, $generator, $this->session, $logger, $clientService])
			->getMock();

		$this->sessionVerifier = new SessionVerifier($logger, $this->session, $this->userSession, $this->cacheFactory, $dispatcher, $this->client);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testOpenIdSessionExpired(): void {
		$this->session->method('get')->with('oca.openid-connect.session-id')->willReturn('SID-1234');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('SID-1234')->willReturn(false);
		$this->cacheFactory->expects(self::once())->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testNoAccessTokenInSession(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, null);

		$this->userSession->expects(self::never())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidCachedAccessToken(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() + 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenNoRefresh(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::never())->method('refreshToken');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenRefresh(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token'],
			['oca.openid-connect.id-token'],
			['oca.openid-connect.refresh-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456', 'id-123456', 'refresh-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('refreshToken')->with('refresh-123456')->willReturn((object)[]);
		$this->session->expects(self::exactly(2))->method('set');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenRefreshError(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token'],
			['oca.openid-connect.id-token'],
			['oca.openid-connect.refresh-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456', 'id-123456', 'refresh-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->expectException(HintException::class);
		$this->expectExceptionMessage('Verifying token failed: access_denied');
		$this->userSession->expects(self::once())->method('logout');
		$this->client->expects(self::once())->method('refreshToken')->with('refresh-123456')->willReturn((object)['error' => 'access_denied']);
		$this->session->expects(self::never())->method('set');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidFreshAccessToken(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::exactly(2))->method('create')->with('oca.openid-connect')->willReturn($cache);
		$exp = \time() + 3600;
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getOpenIdConfig')->willReturn(['client-id' => 'owncloud-client']);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => $exp, 'aud' => 'owncloud-client']);

		$cache->expects(self::once())->method('set')->with('access-123456', $exp);
		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('setAccessToken')->with('access-123456');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidFreshAccessTokenWithIntrospection(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::exactly(2))->method('create')->with('oca.openid-connect')->willReturn($cache);
		$exp = \time() + 3600;
		$this->client->method('getOpenIdConfig')->willReturn(['client-id' => 'owncloud-client']);
		$this->client->method('introspectToken')->willReturn((object)['active' => true, 'exp' => $exp, 'client_id' => 'owncloud-client']);

		$cache->expects(self::once())->method('set')->with('access-123456', $exp);
		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('setAccessToken')->with('access-123456');

		$this->sessionVerifier->verifySession();
	}

	public function provideOpenIdConfig(): array {
		return [
			[null, null],
			[[], null],
			[['post_logout_redirect_uri' => null], null],
			[['post_logout_redirect_uri' => 'http://localhost'], 'http://localhost'],
		];
	}

	/**
	 * @dataProvider provideOpenIdConfig
	 * @param string[]|null $openIdConfig
	 * @param string $expectedLogoutRedirectUri
	 * @throws JsonException
	 */
	public function testLogoutRedirect($openIdConfig, $expectedLogoutRedirectUri): void {
		$this->client->method('getOpenIdConfig')
			->willReturn($openIdConfig);
		$this->client->expects($this->once())
			->method('signOut')
			->with($this->anything(), $expectedLogoutRedirectUri);
		$this->sessionVerifier->afterLogout('dummy', 'dummy');
	}

	/**
	 * SessionVerifier mit echtem Client; ohne $http scheitert der HTTP-Transport,
	 * der Anbieter ist also nicht erreichbar.
	 *
	 * @return array{0: SessionVerifier, 1: MockObject|Logger, 2: MockObject|IClient}
	 */
	private function verifierWithProvider(?IClient $http = null): array {
		$logger = $this->createMock(Logger::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(\json_encode([
			'provider-url' => 'https://idp.example.invalid',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		]));
		if ($http === null) {
			$http = $this->createMock(IClient::class);
			$http->method('get')->willThrowException(new ConnectException(
				'cURL error 6: Could not resolve host: idp.example.invalid',
				new Request('GET', 'https://idp.example.invalid/.well-known/openid-configuration')
			));
		}
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($http);
		$client = new Client($config, $this->createMock(IURLGenerator::class), $this->session, $logger, $clientService);
		$verifier = new SessionVerifier($logger, $this->session, $this->userSession, $this->cacheFactory, $this->createMock(EventDispatcherInterface::class), $client);
		return [$verifier, $logger, $http];
	}

	private function sessionWithTokens(): void {
		$this->session->method('get')->willReturnMap([
			['oca.openid-connect.session-id', null],
			['oca.openid-connect.access-token', 'opaque-access-token'],
			['oca.openid-connect.id-token', 'id-token'],
			['oca.openid-connect.refresh-token', 'refresh-token'],
		]);
	}

	/**
	 * Das Zugriffstoken der Sitzung lässt sich nicht prüfen, weil der Anbieter
	 * nicht erreichbar ist: Sitzung beenden, aber keine Ausnahme - die Anfrage
	 * läuft als nicht angemeldet weiter und landet auf der Anmeldeseite.
	 */
	public function testUnreachableProviderDuringVerificationEndsSessionWithoutError(): void {
		[$verifier, $logger] = $this->verifierWithProvider();
		$this->sessionWithTokens();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects(self::never())->method('set');
		$this->cacheFactory->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');
		$logger->expects(self::once())->method('warning')->with(self::stringContains('Could not resolve host'));
		$logger->expects(self::never())->method('logException');

		$verifier->verifySession();
	}

	/**
	 * Das Token läuft bald ab und soll erneuert werden, der Anbieter ist nicht
	 * erreichbar: Bisher blieb die Sitzung stehen und jede Seite endete in
	 * HTTP 500, bis der Anbieter wieder da war.
	 */
	public function testUnreachableProviderDuringRefreshEndsSessionWithoutError(): void {
		[$verifier, $logger] = $this->verifierWithProvider();
		$this->sessionWithTokens();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(\time() + 60);
		$this->cacheFactory->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');
		$this->session->expects(self::never())->method('set');
		$logger->expects(self::once())->method('warning')->with(self::stringContains('Could not resolve host'));
		$logger->expects(self::never())->method('logException');

		$verifier->verifySession();
	}

	/**
	 * Der Anbieter ist erreichbar, lehnt das Refresh-Token aber ab (400
	 * invalid_grant, etwa nach Widerruf): ebenfalls Sitzung beenden statt HTTP
	 * 500 auf jeder Seite.
	 */
	public function testRefreshRejectedByProviderEndsSessionWithoutError(): void {
		$discovery = $this->createMock(IResponse::class);
		$discovery->method('getStatusCode')->willReturn(200);
		$discovery->method('getBody')->willReturn('{"issuer":"https://idp.example.invalid","token_endpoint":"https://idp.example.invalid/token"}');
		$http = $this->createMock(IClient::class);
		$http->method('get')->willReturn($discovery);
		$http->expects(self::once())->method('post')->with('https://idp.example.invalid/token')->willThrowException(new ClientException(
			'Client error: `POST https://idp.example.invalid/token` resulted in a `400 Bad Request` response: {"error":"invalid_grant"}',
			new Request('POST', 'https://idp.example.invalid/token'),
			new Response(400, [], '{"error":"invalid_grant"}')
		));
		[$verifier, $logger] = $this->verifierWithProvider($http);
		$this->sessionWithTokens();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(\time() + 60);
		$this->cacheFactory->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');
		$this->session->expects(self::never())->method('set');
		$logger->expects(self::once())->method('warning')->with(self::stringContains('400 Bad Request'));
		$logger->expects(self::never())->method('logException');

		$verifier->verifySession();
	}

	public function testAfterLogoutWithUnreachableProvider(): void {
		[$verifier, $logger, $http] = $this->verifierWithProvider();
		// Widerruf und Abmeldung beim Anbieter scheitern, aber nur ein Versuch
		$http->expects(self::once())->method('get');
		$logger->expects(self::once())->method('warning');
		$logger->expects(self::never())->method('logException');
		$this->session->expects(self::exactly(3))->method('remove');

		$verifier->afterLogout('access-token', 'id-token');
	}
}
