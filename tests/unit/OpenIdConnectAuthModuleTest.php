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

namespace OCA\OpenIdConnect\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Jumbojett\OpenIDConnectClientException;
use OC\Memcache\ArrayCache;
use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class OpenIdConnectAuthModuleTest extends TestCase {
	/**
	 * @var OpenIdConnectAuthModule
	 */
	private $authModule;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $cacheFactory;
	/**
	 * @var MockObject | UserLookupService
	 */
	private $lookupService;
	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | AutoProvisioningService
	 */
	private $autoProvisioningService;

	protected function setUp(): void {
		parent::setUp();
		$manager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->lookupService = $this->createMock(UserLookupService::class);

		$config = $this->createMock(IConfig::class);
		$generator = $this->createMock(IURLGenerator::class);
		$session = $this->createMock(ISession::class);
		$clientService = $this->createMock(IClientService::class);
		$this->client = $this->getMockBuilder(Client::class)
			->onlyMethods(['getUserInfo', 'refreshToken', 'signOut', 'getOpenIdConfig', 'verifyJWTsignature', 'getAccessTokenPayload', 'setAccessToken', 'introspectToken', 'getAutoProvisionConfig'])
			->setConstructorArgs([$config, $generator, $session, $this->logger, $clientService])
			->getMock();

		$this->autoProvisioningService = $this->createMock(AutoProvisioningService::class);
		$this->authModule = new OpenIdConnectAuthModule(
			$manager,
			$this->logger,
			$this->cacheFactory,
			$this->lookupService,
			$this->client,
			$this->autoProvisioningService
		);
	}

	/**
	 * @throws LoginException
	 */
	public function testNoBearer(): void {
		$request = $this->createMock(IRequest::class);

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	/**
	 * @throws LoginException
	 */
	public function testNotConfigured(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	/**
	 * @throws LoginException
	 */
	public function testInvalidToken(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getAccessTokenPayload')->willReturn((object)[]);
		$this->client->method('verifyJWTsignature')->willReturn(false);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Token cannot be verified.'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	/**
	 * @throws LoginException
	 */
	public function testInvalidTokenWithIntrospection(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('introspectToken')->willReturn((object)['error' => 'expired']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Verifying token failed: expired'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	/**
	 * @throws LoginException
	 */
	public function testInvalidTokenWithIntrospectionNotActive(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('introspectToken')->willReturn((object)['active' => false]);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Token (as per introspection) is inactive'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	/**
	 * @throws LoginException
	 */
	public function testValidTokenWithIntrospection(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['client-id' => 'owncloud-client']);
		$this->client->method('introspectToken')->willReturn((object)['active' => true, 'exp' => \time() + 3600, 'client_id' => 'owncloud-client']);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@example.com']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertEquals($user, $return);
	}

	/**
	 * @throws LoginException
	 */
	public function testValidTokenWithJWT(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['client-id' => 'owncloud-client']);
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => \time() + 3600, 'aud' => 'owncloud-client']);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@example.com']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertEquals($user, $return);
	}

	public function testExpiredCachedToken(): void {
		$this->expectException(LoginException::class);
		$this->expectExceptionMessage('OpenID Connect token expired');

		$cache = new ArrayCache();
		$cache->set(1234567890, ['exp' => \time() - 5]);

		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->cacheFactory->method('create')->willReturn($cache);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$this->authModule->auth($request);
	}

	/**
	 * @throws LoginException
	 */
	public function testValidTokenWithAutoUpdate(): void {
		$userInfo = (object)['email' => 'foo@example.com'];
		$openIdConfig = ['client-id' => 'owncloud-client', 'auto-provision' => [ 'update' => ['enabled' => true ]]];
		$this->client->method('getOpenIdConfig')->willReturn($openIdConfig);
		$this->client->method('getAutoProvisionConfig')->willReturn($openIdConfig['auto-provision']);
		$this->client->method('getUserInfo')->willReturn($userInfo);
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => time() + 100, 'aud' => 'owncloud-client']);
		$this->autoProvisioningService->method('autoUpdateEnabled')->willReturn(true);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());

		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->autoProvisioningService->expects(self::once())->method('updateAccountInfo')->with($user, $userInfo)->willReturnCallback(function () {
		});
		$this->authModule->auth($request);
	}

	public function providesBearerTokens(): array {
		$b64 = static fn (string $s): string => \rtrim(\strtr(\base64_encode($s), '+/', '-_'), '=');
		return [
			// undurchsichtig: die Introspection-Probe fragt die Discovery ab
			'opaque token' => ['muell-token-123'],
			// JWT ohne eingebetteten Schlüssel: die Signaturprüfung holt die JWKS über die Discovery
			'jwt' => [$b64('{"alg":"RS256","kid":"k1"}') . '.' . $b64('{"sub":"anna","exp":4102444800}') . '.' . $b64('sig')],
		];
	}

	/**
	 * Der Anbieter ist nicht erreichbar: Das Token lässt sich nicht prüfen, die
	 * Anfrage ist nicht angemeldet (401 im Kern) - kein HTTP 500, kein
	 * Stacktrace im Protokoll. Echter Client, nur der HTTP-Transport scheitert.
	 *
	 * @dataProvider providesBearerTokens
	 * @throws LoginException
	 */
	public function testBearerTokenWithUnreachableProviderIsNotAuthenticated(string $token): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(\json_encode([
			'provider-url' => 'https://idp.example.invalid',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		]));
		$http = $this->createMock(IClient::class);
		$http->method('get')->willThrowException(new ConnectException(
			'cURL error 6: Could not resolve host: idp.example.invalid',
			new Request('GET', 'https://idp.example.invalid/.well-known/openid-configuration')
		));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($http);
		$client = new Client($config, $this->createMock(IURLGenerator::class), $this->createMock(ISession::class), $this->logger, $clientService);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$this->lookupService->expects(self::never())->method('lookupUser');
		$authModule = new OpenIdConnectAuthModule(
			$this->createMock(IUserManager::class),
			$this->logger,
			$this->cacheFactory,
			$this->lookupService,
			$client,
			$this->autoProvisioningService
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn("Bearer $token");

		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('Could not resolve host'));
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::never())->method('logException');

		self::assertNull($authModule->auth($request));
	}
}
