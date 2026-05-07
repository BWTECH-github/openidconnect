<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Tests\Unit\Controller;

use OCA\OpenIdConnect\Controller\SettingsController;
use OCP\AppFramework\Http;
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
		$this->controller = new SettingsController('openidconnect', $this->request, $this->config);
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
}
