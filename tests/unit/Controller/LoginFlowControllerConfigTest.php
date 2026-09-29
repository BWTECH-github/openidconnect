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
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Controller\LoginFlowController;
use OCA\OpenIdConnect\ProviderRequestException;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoginFlowControllerConfigTest extends TestCase {
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

	public function testConfigNotConfigured(): void {
		$response = $this->controller->config();
		self::assertEquals(new JSONResponse(), $response);
	}

	public function testConfig(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getWellKnownConfig')->willReturn((object)['foo' => 'bar']);

		$response = $this->controller->config();
		self::assertEquals(new JSONResponse((object)['foo' => 'bar']), $response);
	}

	/**
	 * Anbieter nicht erreichbar oder Discovery-Dokument unbrauchbar: 503 mit
	 * JSON-Fehler statt HTTP 500. Die Clients fallen bei jedem Status außer 200
	 * ohnehin auf ihre Vorgaben zurück; die Antwort nennt nichts Internes.
	 */
	public function testConfigProviderUnavailable(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getWellKnownConfig')->willThrowException(new ProviderRequestException(
			new \RuntimeException('cURL error 6: Could not resolve host: idp.internal.example')
		));
		// die Ursache hat Client::fetchURL() schon protokolliert
		$this->logger->expects(self::never())->method('warning');
		$this->logger->expects(self::never())->method('logException');

		$response = $this->controller->config();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame([
			'error' => 'temporarily_unavailable',
			'error_description' => 'The OpenID Connect provider is not available.',
		], $response->getData());
		self::assertStringNotContainsString('idp.internal.example', $response->render());
	}

	/**
	 * Konfiguration ohne provider-url: dieselbe Antwort, die Ursache kommt hier
	 * ins Protokoll, weil kein Abruf stattfand.
	 */
	public function testConfigWithoutProviderUrl(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getWellKnownConfig')->willThrowException(new OpenIDConnectClientException('The provider URL has not been set'));
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('The provider URL has not been set'));
		$this->logger->expects(self::never())->method('logException');

		$response = $this->controller->config();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('temporarily_unavailable', $response->getData()['error']);
	}
}
