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

namespace OCA\OpenIdConnect\Tests\Unit\Controller;

use Jumbojett\OpenIDConnectClientException;
use OC\HintException;
use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Controller\LoginFlowController;
use OCA\OpenIdConnect\ProviderRequestException;
use OCA\OpenIdConnect\Service\AccountLoginException;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoginFlowControllerLoginTest extends TestCase {
	/**
	 * @var LoginFlowController
	 */
	private $controller;
	/**
	 * @var MockObject | UserLookupService
	 */
	private $userLookup;
	/**
	 * @var MockObject | IRequest
	 */
	private $request;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $memCacheFactory;
	/**
	 * @var MockObject | AutoProvisioningService
	 */
	private $autoProvisioningService;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userLookup = $this->createMock(UserLookupService::class);
		$this->userSession = $this->createMock(Session::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->client = $this->createMock(Client::class);
		$this->memCacheFactory = $this->createMock(ICacheFactory::class);
		$this->autoProvisioningService = $this->createMock(AutoProvisioningService::class);

		$this->controller = new LoginFlowController(
			'openidconnect',
			$this->request,
			$this->userLookup,
			$this->userSession,
			$this->session,
			$this->logger,
			$this->client,
			$this->memCacheFactory,
			$this->autoProvisioningService
		);
	}

	public function testLoginNotConfigured(): void {
		$this->expectException(HintException::class);
		$this->expectExceptionMessage('Configuration issue in openidconnect app');

		$this->controller->login();
	}

	public function testLoginAuthenticateThrowsException(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('authenticate')->willThrowException(new OpenIDConnectClientException('foo'));
		$this->logger->expects(self::once())->method('logException');

		$response = $this->controller->login();

		self::assertSame('login-failed', $response->getTemplateName());
		self::assertSame(503, $response->getStatus());
		self::assertSame('provider', $response->getParams()['reason']);
	}

	public function providesProviderFailures(): array {
		$unreachable = new ProviderRequestException(new \RuntimeException('cURL error 6: Could not resolve host: idp.internal.example'));
		return [
			// Klick auf den Login-Knopf: authorization_endpoint aus der Discovery
			'authenticate' => [$unreachable, null],
			// Rückkehr vom Anbieter: Userinfo-Endpunkt
			'user info' => [null, $unreachable],
		];
	}

	/**
	 * Anbieter nicht erreichbar: übersetzte Fehlerseite mit Status 503 statt
	 * HTTP 500, ohne interne Adressen, ohne Rückfrage beim Anbieter und ohne
	 * Stacktrace im Protokoll - die Ursache hat Client::fetchURL() schon
	 * protokolliert.
	 *
	 * @dataProvider providesProviderFailures
	 */
	public function testLoginWithUnavailableProvider(?\Exception $onAuthenticate, ?\Exception $onUserInfo): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		if ($onAuthenticate !== null) {
			$this->client->method('authenticate')->willThrowException($onAuthenticate);
		}
		if ($onUserInfo !== null) {
			$this->client->method('getUserInfo')->willThrowException($onUserInfo);
		}
		$this->userLookup->expects(self::never())->method('lookupUser');
		$this->logger->expects(self::never())->method('logException');
		$this->logger->expects(self::never())->method('error');
		$this->client->expects(self::never())->method('getEndSessionEndpoint');

		$response = $this->controller->login();

		self::assertSame('login-failed', $response->getTemplateName());
		self::assertSame('guest', $response->getRenderAs());
		self::assertSame(503, $response->getStatus());
		self::assertSame('provider', $response->getParams()['reason']);
		self::assertStringNotContainsString('idp.internal.example', \json_encode($response->getParams()));
	}

	/**
	 * Userinfo-Endpunkt antwortet, aber unbrauchbar (Bibliotheksfehler): wie beim
	 * Anmeldeschritt davor eine Fehlerseite statt HTTP 500.
	 */
	public function testLoginUserInfoClientExceptionIsReported(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willThrowException(new OpenIDConnectClientException('Invalid JWT signature'));
		$this->logger->expects(self::once())->method('logException');

		$response = $this->controller->login();

		self::assertSame(503, $response->getStatus());
		self::assertSame('provider', $response->getParams()['reason']);
		// Der Ausnahmetext steht nur im Protokoll, nicht auf der Seite.
		self::assertStringNotContainsString('Invalid JWT', \json_encode($response->getParams()));
	}

	public function testLoginNoUserInfo(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('requestUserInfo')->willReturn([]);
		$this->expectException(LoginException::class);
		$this->expectExceptionMessage('No user information available.');

		$this->controller->login();
	}

	public function testLoginUnknownUser(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new LoginException('User foo@exmaple.net is not known.'));
		// Die Kennung aus der Ausnahme gehört nicht ins Protokoll.
		$this->logger->expects(self::atLeastOnce())->method('warning')->with(self::logicalNot(self::stringContains('exmaple.net')));

		$response = $this->controller->login();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('login-failed', $response->getTemplateName());
		self::assertSame('guest', $response->getRenderAs());
		self::assertSame(403, $response->getStatus());
		self::assertNotSame('', $response->getParams()['loginUrl']);
		// Ohne Abmeldeadresse des Anbieters kein Abmeldeverweis.
		self::assertSame('', $response->getParams()['providerLogoutUrl']);
	}

	/**
	 * Leitet die Anmeldeseite sofort zum Anbieter weiter, führt der Rückweg auf
	 * die Anmeldeseite mit direct=1 - LoginPageBehaviour leitet dann nicht weiter.
	 */
	public function testLoginUnknownUserWithAutoRedirect(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['autoRedirectOnLoginPage' => true, 'loginButtonName' => 'Firmen-SSO']);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new LoginException('User foo@exmaple.net is not known.'));

		$response = $this->controller->login();

		self::assertStringContainsString('direct=1', $response->getParams()['loginUrl']);
	}

	/**
	 * Die Ursache steht im Protokoll, mit Konfigurationsnamen, aber ohne Wert
	 * aus den Claims.
	 */
	public function testLoginWrongBackendLogsCauseWithoutIdentity(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new AccountLoginException(
			'User is from wrong user backend <OC\User\Database>',
			AccountLoginException::BACKEND_NOT_ALLOWED,
			'OC\User\Database'
		));
		$warnungen = [];
		$this->logger->method('warning')->willReturnCallback(function ($text) use (&$warnungen) {
			$warnungen[] = $text;
		});

		$this->controller->login();

		self::assertCount(1, $warnungen);
		self::assertStringContainsString('allowed-user-backends', $warnungen[0]);
		self::assertStringContainsString('OC\User\Database', $warnungen[0]);
	}

	public function testLoginUnknownAccountLogsNoIdentity(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new AccountLoginException(
			'User with foo@exmaple.net is not known.',
			AccountLoginException::ACCOUNT_UNKNOWN
		));
		$warnungen = [];
		$this->logger->method('warning')->willReturnCallback(function ($text) use (&$warnungen) {
			$warnungen[] = $text;
		});

		$this->controller->login();

		self::assertStringContainsString('no account matches', $warnungen[0]);
		self::assertStringNotContainsString('exmaple.net', $warnungen[0]);
	}

	/**
	 * Nennt der Anbieter eine Abmeldeadresse, bietet die Seite sie an - mit
	 * client_id und der konfigurierten Rücksprungadresse.
	 */
	public function testLoginUnknownUserOffersProviderLogout(): void {
		$this->client->method('getOpenIdConfig')->willReturn([
			'client-id' => 'owncloud',
			'post_logout_redirect_uri' => 'https://cloud.example/index.php/login',
		]);
		$this->client->method('getEndSessionEndpoint')->willReturn('https://idp.example/session/end');
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new LoginException('x'));
		$this->client->expects(self::atLeastOnce())->method('clearRedirectUrl');

		$response = $this->controller->login();

		self::assertSame(
			'https://idp.example/session/end?client_id=owncloud&post_logout_redirect_uri=' . \rawurlencode('https://cloud.example/index.php/login'),
			$response->getParams()['providerLogoutUrl']
		);
	}

	public function testLoginCreateSessionFailed(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(false);

		$response = $this->controller->login();
		self::assertEquals(new RedirectResponse('/'), $response);
	}

	public function testLoginCreateSuccess(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		$this->session->expects(self::exactly(3))->method('set')->withConsecutive(
			['oca.openid-connect.id-token', 'id'],
			['oca.openid-connect.access-token', 'access'],
			['oca.openid-connect.refresh-token', 'refresh']
		);

		$response = $this->controller->login();

		// Ohne angeforderte Seite die Startseite des Kerns - welche App das
		// ist, entscheidet OC_Util::getDefaultPageUrl() (im Redesign dashboard).
		self::assertEquals(\OC_Util::getDefaultPageUrl(), $response->getRedirectURL());
	}

	/**
	 * Ein Ziel mit '@' zeigt nicht mehr auf diesen Server und wird verworfen.
	 */
	public function testLoginCreateSuccessWithForeignRedirect(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('readRedirectUrl')->willReturn(':user@evil.example/path');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);

		$response = $this->controller->login();

		self::assertEquals(\OC_Util::getDefaultPageUrl(), $response->getRedirectURL());
		self::assertStringNotContainsString('evil.example', $response->getRedirectURL());
	}

	/**
	 * Ein kodierter Zeilenumbruch im Ziel ergab eine Weiterleitung ohne
	 * Location (PHP verweigert den Header) - jetzt die Startseite.
	 */
	public function testLoginCreateSuccessWithControlCharacterRedirect(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('readRedirectUrl')->willReturn('index.php/apps/files/%0d%0aX-Test: 1');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);

		$response = $this->controller->login();

		self::assertEquals(\OC_Util::getDefaultPageUrl(), $response->getRedirectURL());
	}

	/**
	 * Das gespeicherte Ziel gilt einmal: nach dem Lesen wird es gelöscht, und
	 * ein neuer Vorgang (ohne Antwort des Anbieters) beginnt ohne altes Ziel.
	 */
	public function testRedirectTargetIsClearedAtStartAndAfterUse(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('readRedirectUrl')->willReturn('index.php/apps/files/');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		// Kein code/error im Request: Start und Verbrauch löschen je einmal.
		$this->client->expects(self::exactly(2))->method('clearRedirectUrl');

		$this->controller->login();
	}

	public function testLoginCreateSuccessWithRedirect(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$this->client->method('readRedirectUrl')->willReturn('index.php/apps/oauth2/foo/bla');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		$this->session->expects(self::exactly(3))->method('set')->withConsecutive(
			['oca.openid-connect.id-token', 'id'],
			['oca.openid-connect.access-token', 'access'],
			['oca.openid-connect.refresh-token', 'refresh']
		);

		$response = $this->controller->login();

		self::assertEquals('http://localhost/index.php/apps/oauth2/foo/bla', $response->getRedirectURL());
	}

	public function testLoginCreateSuccessWithOCISRoutingPolicyCookie(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net','ocis.routing.policy'=>'ocis']);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$this->client->method('readRedirectUrl')->willReturn('index.php/apps/oauth2/foo/bla');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		$this->session->expects(self::exactly(3))->method('set')->withConsecutive(
			['oca.openid-connect.id-token', 'id'],
			['oca.openid-connect.access-token', 'access'],
			['oca.openid-connect.refresh-token', 'refresh']
		);

		$response = $this->controller->login();

		self::assertEquals('http://localhost/index.php/apps/oauth2/foo/bla', $response->getRedirectURL());

		$headers = $response->getHeaders();
		self::assertEquals('owncloud-selector=ocis;path=/;', $headers['Set-Cookie']);
	}

	public function providesRoutingClaimConfig(): array {
		return [
			// Schreibweise aus openidconnect 2.1.x/2.2.x (upstream #289)
			'alter Schlüssel mit Tippfehler' => [['ocis-routing-poclicy-claim' => 'routing'], 'ocis'],
			// die richtige Schreibweise hat Vorrang
			'beide Schreibweisen' => [['ocis-routing-poclicy-claim' => 'routing', 'ocis-routing-policy-claim' => 'ocis.routing.policy'], 'oc10'],
		];
	}

	/**
	 * @dataProvider providesRoutingClaimConfig
	 */
	public function testLoginRoutingClaimFromPredecessorConfig(array $config, string $expectedCookieValue): void {
		$this->client->method('getOpenIdConfig')->willReturn($config);
		$this->client->method('getUserInfo')->willReturn((object)[
			'email' => 'foo@exmaple.net',
			'routing' => 'ocis',
			'ocis.routing.policy' => 'oc10',
		]);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);

		$response = $this->controller->login();

		self::assertEquals("owncloud-selector=$expectedCookieValue;path=/;", $response->getHeaders()['Set-Cookie']);
	}
}
