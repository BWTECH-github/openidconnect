<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
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

use Jumbojett\OpenIDConnectClientException;
use OCA\OpenIdConnect\Client;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\ISession;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Übernommene Konfigurationen der Vorgänger müssen nach dem Umzug so weiter
 * wirken wie dort:
 *
 * - bis upstream 2.3.4 (ownCloud 10) gab es keine Audience-Prüfung und keinen
 *   Schlüssel dafür. Keycloak, Entra ID und AD FS nennen in "aud" nicht die
 *   client-id, wohl aber in "azp"/"appid"/"client_id" - darüber muss ein
 *   solches Token weiter angenommen werden;
 * - ab upstream 2.3.5/2.4.2 gibt es den Schlüssel "audience", der die
 *   client-id als erwartetes Publikum ersetzt.
 */
class ClientAudienceMigrationTest extends TestCase {
	private const CLIENT_ID = 'owncloud-client';

	/** @var MockObject|IConfig */
	private $config;
	/** @var MockObject|ILogger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(ILogger::class);
	}

	private function useSystemConfig(array $extra): void {
		$this->config->method('getAppValue')->willReturn(null);
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($extra) {
			if ($key === 'openid-connect') {
				return \array_merge([
					'provider-url' => 'https://idp.example.org',
					'client-id' => self::CLIENT_ID,
					'client-secret' => 'secret',
				], $extra);
			}
			return null;
		});
	}

	private function useAppConfig(array $extra): void {
		$this->config->method('getAppValue')->willReturnCallback(static function ($app, $key) use ($extra) {
			if ($app === 'openidconnect' && $key === 'openid-connect') {
				return \json_encode(\array_merge([
					'provider-url' => 'https://idp.example.org',
					'client-id' => self::CLIENT_ID,
					'client-secret' => 'secret',
				], $extra));
			}
			return null;
		});
		$this->config->method('getSystemValue')->willReturn(null);
	}

	private function jwtClient(array $payload): Client {
		$client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([
				$this->config,
				$this->createMock(IURLGenerator::class),
				$this->createMock(ISession::class),
				$this->logger,
				$this->createMock(IClientService::class),
			])
			->onlyMethods(['getAccessTokenPayload', 'verifyJWTsignature', 'setAccessToken'])
			->getMock();
		$client->method('getAccessTokenPayload')->willReturn((object)$payload);
		$client->method('verifyJWTsignature')->willReturn(true);
		return $client;
	}

	private function introspectionClient(array $introspection): Client {
		$client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([
				$this->config,
				$this->createMock(IURLGenerator::class),
				$this->createMock(ISession::class),
				$this->logger,
				$this->createMock(IClientService::class),
			])
			->onlyMethods(['getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->getMock();
		$client->method('getAccessTokenPayload')->willReturn(null);
		$client->method('introspectToken')->willReturn((object)$introspection);
		return $client;
	}

	private function expectRejected(): void {
		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');
	}

	public function providesPredecessorTokens(): array {
		$exp = \time() + 3600;
		return [
			// Keycloak: "aud" ist "account" oder fehlt, "azp" ist die client-id
			'Keycloak, aud account' => [['exp' => $exp, 'aud' => 'account', 'azp' => self::CLIENT_ID]],
			'Keycloak 26, ohne aud' => [['exp' => $exp, 'azp' => self::CLIENT_ID]],
			// Entra ID v1.0 / AD FS: "aud" ist die App-ID-URI, "appid" die client-id
			'Entra ID v1.0' => [['exp' => $exp, 'aud' => 'api://owncloud', 'appid' => self::CLIENT_ID]],
			'AD FS' => [['exp' => $exp, 'aud' => 'microsoft:identityserver:owncloud', 'appid' => self::CLIENT_ID]],
			// Anbieter, die "aud" schon immer richtig gesetzt haben
			'aud ist die client-id' => [['exp' => $exp, 'aud' => self::CLIENT_ID]],
		];
	}

	/**
	 * Eine Konfiguration aus upstream <= 2.3.4 ohne jede Audience-Angabe: Die
	 * Token ihres Anbieters wurden dort angenommen und müssen es hier bleiben.
	 *
	 * @dataProvider providesPredecessorTokens
	 */
	public function testConfigWithoutAudienceKeepsWorkingFromSystemConfig(array $payload): void {
		$this->useSystemConfig([]);
		self::assertSame($payload['exp'], $this->jwtClient($payload)->verifyToken('jwt'));
	}

	/**
	 * Dasselbe für eine Konfiguration, die in der App-Konfiguration liegt
	 * (upstream >= 2.1.0 las sie dort zuerst).
	 *
	 * @dataProvider providesPredecessorTokens
	 */
	public function testConfigWithoutAudienceKeepsWorkingFromAppConfig(array $payload): void {
		$this->useAppConfig([]);
		self::assertSame($payload['exp'], $this->jwtClient($payload)->verifyToken('jwt'));
	}

	public function testTokenOfAnotherClientIsStillRejected(): void {
		$this->useSystemConfig([]);
		$this->expectRejected();
		$this->jwtClient(['exp' => \time() + 3600, 'aud' => 'account', 'azp' => 'other-client'])->verifyToken('jwt');
	}

	/**
	 * Der ranghöchste Claim mit Wert entscheidet - ein ehrliches "azp" eines
	 * fremden Clients wird nicht durch ein "client_id" mit unserem Namen
	 * überstimmt.
	 */
	public function testMostAuthoritativeClientClaimDecides(): void {
		$this->useSystemConfig([]);
		$this->expectRejected();
		$this->jwtClient([
			'exp' => \time() + 3600,
			'aud' => 'account',
			'azp' => 'other-client',
			'client_id' => self::CLIENT_ID,
		])->verifyToken('jwt');
	}

	public function testTokenWithoutAnyNamingClaimIsRejected(): void {
		$this->useSystemConfig([]);
		$this->expectRejected();
		$this->jwtClient(['exp' => \time() + 3600, 'aud' => 'account'])->verifyToken('jwt');
	}

	/**
	 * Ein aus upstream 2.3.5/2.4.2 übernommener "audience"-Schlüssel ersetzt die
	 * client-id als erwartetes Publikum.
	 *
	 * @dataProvider providesConfiguredAudience
	 * @param string|string[] $configured
	 */
	public function testConfiguredAudienceFromPredecessorIsHonoured($configured, string $tokenAud): void {
		$this->useSystemConfig(['audience' => $configured]);
		$exp = \time() + 3600;
		self::assertSame($exp, $this->jwtClient(['exp' => $exp, 'aud' => $tokenAud])->verifyToken('jwt'));
	}

	public function providesConfiguredAudience(): array {
		return [
			'string' => ['api://owncloud', 'api://owncloud'],
			'liste, erster Wert' => [['api://owncloud', 'https://owncloud.example.org'], 'api://owncloud'],
			'liste, zweiter Wert' => [['api://owncloud', 'https://owncloud.example.org'], 'https://owncloud.example.org'],
		];
	}

	public function testConfiguredAudienceFromAppConfigIsHonoured(): void {
		$this->useAppConfig(['audience' => 'api://owncloud']);
		$exp = \time() + 3600;
		self::assertSame($exp, $this->jwtClient(['exp' => $exp, 'aud' => ['api://owncloud', 'other']])->verifyToken('jwt'));
	}

	/**
	 * Mit "audience" zählt nur noch "aud": die client-id dort genügt nicht mehr,
	 * und der Weg über "azp" ist abgeschaltet - wie bei upstream.
	 */
	public function testConfiguredAudienceReplacesClientIdAndDisablesTheClaimFallback(): void {
		$this->useSystemConfig(['audience' => 'api://owncloud']);
		$this->expectRejected();
		$this->jwtClient(['exp' => \time() + 3600, 'aud' => self::CLIENT_ID, 'azp' => self::CLIENT_ID])->verifyToken('jwt');
	}

	/**
	 * @dataProvider providesUnusableAudience
	 * @param mixed $configured
	 */
	public function testUnusableAudienceRejectsEverythingAndSaysSo($configured): void {
		$this->useSystemConfig(['audience' => $configured]);
		$this->logger->expects(self::once())->method('warning');
		$this->expectRejected();
		$this->jwtClient(['exp' => \time() + 3600, 'aud' => self::CLIENT_ID, 'azp' => self::CLIENT_ID])->verifyToken('jwt');
	}

	public function providesUnusableAudience(): array {
		return [
			'leerer String' => [''],
			'leere Liste' => [[]],
			'Zahl' => [42],
		];
	}

	public function testIntrospectionWithClientIdKeepsWorking(): void {
		$this->useSystemConfig([]);
		$exp = \time() + 3600;
		self::assertSame($exp, $this->introspectionClient([
			'active' => true,
			'exp' => $exp,
			'aud' => 'account',
			'client_id' => self::CLIENT_ID,
		])->verifyToken('opaque'));
	}

	public function testIntrospectionHonoursConfiguredAudience(): void {
		$this->useSystemConfig(['audience' => 'api://owncloud']);
		$exp = \time() + 3600;
		self::assertSame($exp, $this->introspectionClient([
			'active' => true,
			'exp' => $exp,
			'aud' => 'api://owncloud',
			'client_id' => 'somebody-else',
		])->verifyToken('opaque'));
	}

	public function testIntrospectionWithConfiguredAudienceIgnoresClientId(): void {
		$this->useSystemConfig(['audience' => 'api://owncloud']);
		$this->expectRejected();
		$this->introspectionClient([
			'active' => true,
			'exp' => \time() + 3600,
			'aud' => 'account',
			'client_id' => self::CLIENT_ID,
		])->verifyToken('opaque');
	}

	/**
	 * token-aud-check => false (eigener Schalter dieses Forks) schaltet weiterhin
	 * alles ab, auch wenn zusätzlich "audience" gesetzt ist.
	 */
	public function testDisabledCheckStillWinsOverAudience(): void {
		$this->useSystemConfig(['token-aud-check' => false, 'audience' => 'api://owncloud']);
		$exp = \time() + 3600;
		self::assertSame($exp, $this->jwtClient(['exp' => $exp, 'aud' => 'anything'])->verifyToken('jwt'));
	}
}
