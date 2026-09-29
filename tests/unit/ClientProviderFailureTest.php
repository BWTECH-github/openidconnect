<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
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
use Jumbojett\OpenIDConnectClientException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\ProviderRequestException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\ILogger;
use OCP\ISession;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Client::fetchURL() mit echtem Ablauf und einem HTTP-Client, der scheitert:
 * Der Anbieter ist nicht erreichbar, antwortet mit Fehlerstatus oder liefert
 * Unbrauchbares.
 */
class ClientProviderFailureTest extends TestCase {
	private const PROVIDER = 'https://idp.example.invalid';
	private const DISCOVERY = self::PROVIDER . '/.well-known/openid-configuration';

	/** @var MockObject | ILogger */
	private $logger;
	/** @var MockObject | IClient */
	private $http;
	private Client $client;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(\json_encode([
			'provider-url' => self::PROVIDER,
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		]));
		$config->method('getSystemValue')->willReturnArgument(1);
		$this->logger = $this->createMock(ILogger::class);
		$this->http = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->http);

		$this->client = new Client(
			$config,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ISession::class),
			$this->logger,
			$clientService
		);
	}

	private static function unreachable(string $url = self::DISCOVERY): ConnectException {
		return new ConnectException(
			'cURL error 6: Could not resolve host: idp.example.invalid (see https://curl.se/libcurl/c/libcurl-errors.html) for ' . $url,
			new Request('GET', $url)
		);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	public function testUnreachableProviderRaisesLibraryExceptionWithoutAddress(): void {
		$this->http->method('get')->willThrowException(self::unreachable());
		$this->logger->expects(self::once())->method('warning')->with(
			self::logicalAnd(
				self::stringContains(self::DISCOVERY),
				self::stringContains('ConnectException'),
				self::stringContains('Could not resolve host')
			)
		);
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::never())->method('logException');

		try {
			$this->client->getWellKnownConfig();
			self::fail('expected an exception');
		} catch (OpenIDConnectClientException $ex) {
			self::assertInstanceOf(ProviderRequestException::class, $ex);
			self::assertStringNotContainsString('idp.example.invalid', $ex->getMessage());
			self::assertInstanceOf(ConnectException::class, $ex->getPrevious());
		}
	}

	public function testNoSecondAttemptAfterNetworkFailureInTheSameRequest(): void {
		$this->http->expects(self::once())->method('get')->willThrowException(self::unreachable());
		$this->http->expects(self::never())->method('post');
		// nur eine Zeile mit Ursache, auch wenn danach weitere Aufrufe scheitern
		$this->logger->expects(self::once())->method('warning');

		try {
			$this->client->getWellKnownConfig();
			self::fail('expected an exception');
		} catch (ProviderRequestException) {
		}
		$this->expectException(ProviderRequestException::class);
		$this->client->revokeToken('access-token');
	}

	public function testHttpErrorResponseIsNotRememberedAsOutage(): void {
		$request = new Request('GET', self::DISCOVERY);
		$this->http->expects(self::exactly(2))->method('get')->willThrowException(
			new ClientException('Client error: `GET ' . self::DISCOVERY . '` resulted in a `404 Not Found` response', $request, new Response(404))
		);
		$this->logger->expects(self::exactly(2))->method('warning')->with(self::stringContains('404 Not Found'));

		foreach ([1, 2] as $attempt) {
			try {
				$this->client->getWellKnownConfig();
				self::fail("expected an exception on attempt $attempt");
			} catch (ProviderRequestException $ex) {
				self::assertInstanceOf(ClientException::class, $ex->getPrevious());
			}
		}
	}

	public function testRequestsCarryTheLibraryTimeout(): void {
		$this->http->expects(self::once())->method('get')
			->with(self::DISCOVERY, ['headers' => [], 'timeout' => 60])
			->willReturn($this->response(200, '{"issuer":"https://idp.example.invalid"}'));

		self::assertEquals((object)['issuer' => self::PROVIDER], $this->client->getWellKnownConfig());
	}

	public function providesUnusableDiscoveryDocuments(): array {
		return [
			'html page' => ['<!DOCTYPE html><html><body>Proxy error</body></html>'],
			'empty body' => [''],
			'json null' => ['null'],
			'json list' => ['[]'],
			'json string' => ['"https://idp.example.invalid"'],
			'json number' => ['42'],
		];
	}

	/**
	 * @dataProvider providesUnusableDiscoveryDocuments
	 */
	public function testUnusableDiscoveryDocumentRaisesProviderRequestException(string $body): void {
		$this->http->method('get')->willReturn($this->response(200, $body));
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains(self::DISCOVERY));

		$this->expectException(ProviderRequestException::class);
		$this->client->getWellKnownConfig();
	}

	public function testUnreachableProviderDuringIntrospectionIsNotTreatedAsMissingEndpoint(): void {
		// "kein Introspection-Endpunkt" führt auf den Userinfo-Weg ohne
		// Audience-Prüfung - ein nicht erreichbarer Anbieter darf nicht dort landen
		$this->http->method('get')->willThrowException(self::unreachable());
		$this->http->expects(self::never())->method('post');

		$this->expectException(ProviderRequestException::class);
		$this->client->verifyToken('opaque-access-token');
	}
}
