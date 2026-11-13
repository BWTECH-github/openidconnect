<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Tests\Unit\Controller;

use OCA\OpenIdConnect\Controller\SettingsController;
use OCP\AppFramework\Http;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class SettingsControllerTest extends TestCase {
	/** @var SettingsController */
	private $controller;
	/** @var MockObject|IRequest */
	private $request;
	/** @var MockObject|IConfig */
	private $config;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->controller = new SettingsController('openidconnect', $this->request, $this->config, null, $this->cacheFactory(true));
	}

	private function cacheFactory(bool $available): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		return $factory;
	}

	public function testSaveConfigIsRejectedWithoutDistributedCache(): void {
		$this->request->method('getParams')->willReturn([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'client',
			'client-secret' => 'secret',
		]);
		$this->config->expects(self::never())->method('setAppValue');
		$controller = new SettingsController('openidconnect', $this->request, $this->config, null, $this->cacheFactory(false));

		$response = $controller->saveConfig();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('error', $response->getData()['status']);
	}

	public function testGetConfigUsesAppConfig(): void {
		$this->config->method('getAppValue')->willReturn(\json_encode([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'auto-provision' => [
				'enabled' => true,
				'groups' => ['oidc-users'],
			],
		]));

		$response = $this->controller->getConfig();
		$data = $response->getData();

		self::assertSame('success', $data['status']);
		self::assertSame('appconfig', $data['source']);
		self::assertSame('https://idp.example.test', $data['config']['provider-url']);
		self::assertSame("oidc-users", $data['config']['auto-provision-groups']);
		self::assertTrue($data['config']['auto-provision-enabled']);
	}

	public function testGetConfigFallsBackToSystemConfig(): void {
		$this->config->method('getAppValue')->willReturn(null);
		$this->config->method('getSystemValue')->willReturn([
			'provider-url' => 'https://system-idp.example.test',
			'client-id' => 'system-client',
			'client-secret' => 'system-secret',
		]);

		$response = $this->controller->getConfig();
		$data = $response->getData();

		self::assertSame('system', $data['source']);
		self::assertSame('https://system-idp.example.test', $data['config']['provider-url']);
	}

	public function testSaveConfig(): void {
		$this->request->method('getParams')->willReturn([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'scopes' => "openid\nprofile\nemail",
			'mode' => 'userid',
			'search-attribute' => 'preferred_username',
			'loginButtonName' => 'Login via SSO',
			'autoRedirectOnLoginPage' => '1',
			'auto-provision-enabled' => '1',
			'auto-provision-groups' => "oidc-users\nemployees",
			'auto-provision-update-enabled' => '1',
			'provider-params' => '{"authorization_endpoint":"https://idp.example.test/auth"}',
		]);

		$this->config->expects(self::once())
			->method('setAppValue')
			->with('openidconnect', 'openid-connect', self::callback(static function ($encodedConfig) {
				$config = \json_decode($encodedConfig, true);
				return $config['provider-url'] === 'https://idp.example.test'
					&& $config['autoRedirectOnLoginPage'] === true
					&& $config['auto-provision']['groups'] === ['oidc-users', 'employees']
					&& $config['auto-provision']['update']['enabled'] === true
					&& $config['provider-params']['authorization_endpoint'] === 'https://idp.example.test/auth';
			}));

		$response = $this->controller->saveConfig();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('success', $response->getData()['status']);
	}

	public function testSaveConfigRejectsInvalidJson(): void {
		$this->request->method('getParams')->willReturn([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'provider-params' => '{broken',
		]);

		$response = $this->controller->saveConfig();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('error', $response->getData()['status']);
	}

	public function testResetConfigDeletesAppConfig(): void {
		$this->config->expects(self::once())
			->method('deleteAppValue')
			->with('openidconnect', 'openid-connect');
		$this->config->method('getAppValue')->willReturn(null);
		$this->config->method('getSystemValue')->willReturn([]);

		$response = $this->controller->resetConfig();
		self::assertSame('empty', $response->getData()['source']);
	}

	/**
	 * Eine aus der Vorgängerinstanz übernommene config.php-Konfiguration trägt
	 * Schlüssel, die das Formular nicht kennt. Das erste Speichern schreibt in
	 * die App-Konfiguration, die config.php zählt danach nicht mehr - diese
	 * Schlüssel dürfen dabei nicht verloren gehen.
	 *
	 * @return array das gespeicherte, dekodierte JSON
	 */
	private function saveOverSystemConfig(array $systemConfig, array $params): array {
		$this->config->method('getAppValue')->willReturn(null);
		$this->config->method('getSystemValue')->willReturn($systemConfig);
		$this->request->method('getParams')->willReturn($params + [
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
		]);
		$saved = null;
		$this->config->expects(self::once())
			->method('setAppValue')
			->with('openidconnect', 'openid-connect', self::callback(static function ($encoded) use (&$saved) {
				$saved = \json_decode($encoded, true);
				return true;
			}));

		$response = $this->controller->saveConfig();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		return $saved;
	}

	public function testSaveKeepsKeysTheFormDoesNotManage(): void {
		$saved = $this->saveOverSystemConfig([
			'provider-url' => 'https://old-idp.example.test',
			'client-id' => 'old',
			'client-secret' => 'old-secret',
			'token-aud-check' => false,
			'audience' => ['api://owncloud'],
			'custom-key' => 'bleibt',
		], []);

		// was das Formular abbildet, kommt aus dem Formular
		self::assertSame('https://idp.example.test', $saved['provider-url']);
		self::assertSame('owncloud', $saved['client-id']);
		// was es nicht abbildet, bleibt erhalten
		self::assertFalse($saved['token-aud-check']);
		self::assertSame(['api://owncloud'], $saved['audience']);
		self::assertSame('bleibt', $saved['custom-key']);
	}

	public function testFormManagedKeyClearedInTheFormIsDropped(): void {
		$saved = $this->saveOverSystemConfig([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'redirect-url' => 'https://old.example.test/redirect',
		], ['redirect-url' => '']);

		self::assertArrayNotHasKey('redirect-url', $saved);
	}

	public function testSaveKeepsUnknownAutoProvisionKeys(): void {
		$saved = $this->saveOverSystemConfig([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'auto-provision' => ['enabled' => true, 'custom-sub-key' => 'bleibt'],
		], ['auto-provision-enabled' => '0']);

		self::assertFalse($saved['auto-provision']['enabled']);
		self::assertSame('bleibt', $saved['auto-provision']['custom-sub-key']);
	}

	public function testLegacyRoutingClaimIsShownAndSavedUnderTheRightName(): void {
		$this->config->method('getAppValue')->willReturn(null);
		$this->config->method('getSystemValue')->willReturn([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'ocis-routing-poclicy-claim' => 'routing',
		]);

		$data = $this->controller->getConfig()->getData();
		self::assertSame('routing', $data['config']['ocis-routing-policy-claim']);
	}

	public function testLegacyRoutingClaimIsNotCarriedAlongOnSave(): void {
		$saved = $this->saveOverSystemConfig([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'ocis-routing-poclicy-claim' => 'routing',
		], ['ocis-routing-policy-claim' => 'routing']);

		self::assertSame('routing', $saved['ocis-routing-policy-claim']);
		self::assertArrayNotHasKey('ocis-routing-poclicy-claim', $saved);
	}

	public function testSavingTwiceGivesTheSameResult(): void {
		$system = [
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
			'token-aud-check' => false,
		];
		$first = $this->saveOverSystemConfig($system, []);

		// zweiter Lauf auf dem, was der erste gespeichert hat
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'provider-url' => 'https://idp.example.test',
			'client-id' => 'owncloud',
			'client-secret' => 'secret',
		]);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(\json_encode($first));
		$second = null;
		$config->expects(self::once())->method('setAppValue')
			->with('openidconnect', 'openid-connect', self::callback(static function ($encoded) use (&$second) {
				$second = \json_decode($encoded, true);
				return true;
			}));
		(new SettingsController('openidconnect', $request, $config, null, $this->cacheFactory(true)))->saveConfig();

		self::assertSame($first, $second);
	}
}
