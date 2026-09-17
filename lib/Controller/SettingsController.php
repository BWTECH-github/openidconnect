<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * Modified by BW-Tech GmbH on 2026-09-17.
 * Changes:
 *   - validation messages are translated (they reach the admin page as is)
 *   - no hard-coded English login button text: an empty field stays empty,
 *     the login page then shows "OpenID Connect"
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Controller;

use OCA\OpenIdConnect\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class SettingsController extends Controller {
	private const CONFIG_KEY = 'openid-connect';

	public function __construct(
		$appName,
		IRequest $request,
		private readonly IConfig $config,
		private ?IL10N $l10n = null,
	) {
		parent::__construct($appName, $request);
	}

	private function l10n(): IL10N {
		if ($this->l10n === null) {
			$this->l10n = \OC::$server->getL10N(Application::APPID);
		}
		return $this->l10n;
	}

	public function getConfig() {
		$config = $this->getEffectiveConfig();
		return new JSONResponse([
			'status' => 'success',
			'source' => $config['source'],
			'config' => $this->normalizeConfig($config['config']),
			'raw' => $this->encodeConfig($config['config']),
		]);
	}

	public function saveConfig() {
		try {
			$config = $this->buildConfig($this->request->getParams());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'status' => 'error',
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setAppValue(Application::APPID, self::CONFIG_KEY, $this->encodeConfig($config));
		return new JSONResponse([
			'status' => 'success',
			'source' => 'appconfig',
			'config' => $this->normalizeConfig($config),
			'raw' => $this->encodeConfig($config),
		]);
	}

	public function resetConfig() {
		$this->config->deleteAppValue(Application::APPID, self::CONFIG_KEY);
		$config = $this->getEffectiveConfig();

		return new JSONResponse([
			'status' => 'success',
			'source' => $config['source'],
			'config' => $this->normalizeConfig($config['config']),
			'raw' => $this->encodeConfig($config['config']),
		]);
	}

	private function getEffectiveConfig(): array {
		$appConfig = $this->config->getAppValue(Application::APPID, self::CONFIG_KEY, null);
		if ($appConfig) {
			$decoded = \json_decode($appConfig, true);
			if (\json_last_error() === JSON_ERROR_NONE && \is_array($decoded)) {
				return [
					'source' => 'appconfig',
					'config' => $decoded,
				];
			}
		}

		$systemConfig = $this->config->getSystemValue(self::CONFIG_KEY, []);
		return [
			'source' => \is_array($systemConfig) && $systemConfig !== [] ? 'system' : 'empty',
			'config' => \is_array($systemConfig) ? $systemConfig : [],
		];
	}

	private function normalizeConfig(array $config): array {
		$autoProvision = $config['auto-provision'] ?? [];
		$autoUpdate = $autoProvision['update'] ?? [];

		return [
			'provider-url' => (string)($config['provider-url'] ?? ''),
			'client-id' => (string)($config['client-id'] ?? ''),
			'client-secret' => (string)($config['client-secret'] ?? ''),
			'scopes' => $this->listToText($config['scopes'] ?? ['openid', 'profile', 'email']),
			'mode' => (string)($config['mode'] ?? 'userid'),
			'search-attribute' => (string)($config['search-attribute'] ?? 'email'),
			'loginButtonName' => (string)($config['loginButtonName'] ?? ''),
			'autoRedirectOnLoginPage' => (bool)($config['autoRedirectOnLoginPage'] ?? false),
			'insecure' => (bool)($config['insecure'] ?? false),
			'redirect-url' => (string)($config['redirect-url'] ?? ''),
			'post_logout_redirect_uri' => (string)($config['post_logout_redirect_uri'] ?? ''),
			'allowed-user-backends' => $this->listToText($config['allowed-user-backends'] ?? []),
			'token-introspection-endpoint-client-id' => (string)($config['token-introspection-endpoint-client-id'] ?? ''),
			'token-introspection-endpoint-client-secret' => (string)($config['token-introspection-endpoint-client-secret'] ?? ''),
			'exchange-token-mode-before-introspection' => (string)($config['exchange-token-mode-before-introspection'] ?? ''),
			'use-access-token-payload-for-user-info' => (bool)($config['use-access-token-payload-for-user-info'] ?? false),
			'use-access-token-introspection-for-user-info' => (bool)($config['use-access-token-introspection-for-user-info'] ?? false),
			'jwt-self-signed-jwk-header-supported' => (bool)($config['jwt-self-signed-jwk-header-supported'] ?? false),
			'ocis-routing-policy-claim' => (string)($config['ocis-routing-policy-claim'] ?? ''),
			'ocis-routing-policy-cookie' => (string)($config['ocis-routing-policy-cookie'] ?? 'owncloud-selector'),
			'ocis-routing-policy-cookie-directives' => (string)($config['ocis-routing-policy-cookie-directives'] ?? 'path=/;'),
			'provider-params' => $this->encodeJsonField($config['provider-params'] ?? []),
			'auth-params' => $this->encodeJsonField($config['auth-params'] ?? []),
			'auto-provision-enabled' => (bool)($autoProvision['enabled'] ?? false),
			'auto-provision-groups' => $this->listToText($autoProvision['groups'] ?? []),
			'auto-provision-email-claim' => (string)($autoProvision['email-claim'] ?? ''),
			'auto-provision-display-name-claim' => (string)($autoProvision['display-name-claim'] ?? ''),
			'auto-provision-picture-claim' => (string)($autoProvision['picture-claim'] ?? ''),
			'auto-provision-provisioning-claim' => (string)($autoProvision['provisioning-claim'] ?? ''),
			'auto-provision-provisioning-attribute' => (string)($autoProvision['provisioning-attribute'] ?? ''),
			'auto-provision-update-enabled' => (bool)($autoUpdate['enabled'] ?? false),
		];
	}

	private function buildConfig(array $params): array {
		$config = [];

		$config['provider-url'] = $this->requiredString($params, 'provider-url', $this->l10n()->t('Provider URL is required'));
		$config['client-id'] = $this->requiredString($params, 'client-id', $this->l10n()->t('Client ID is required'));
		$config['client-secret'] = $this->requiredString($params, 'client-secret', $this->l10n()->t('Client secret is required'));
		$config['scopes'] = $this->parseList((string)($params['scopes'] ?? 'openid profile email'));
		$config['mode'] = ($params['mode'] ?? 'userid') === 'email' ? 'email' : 'userid';
		$config['search-attribute'] = $this->string($params, 'search-attribute', 'email');
		$this->setStringIfPresent($config, $params, 'loginButtonName');

		$this->setBool($config, $params, 'autoRedirectOnLoginPage');
		$this->setBool($config, $params, 'insecure');
		$this->setStringIfPresent($config, $params, 'redirect-url');
		$this->setStringIfPresent($config, $params, 'post_logout_redirect_uri');
		$this->setListIfPresent($config, $params, 'allowed-user-backends');
		$this->setStringIfPresent($config, $params, 'token-introspection-endpoint-client-id');
		$this->setStringIfPresent($config, $params, 'token-introspection-endpoint-client-secret');
		$this->setEnumIfPresent($config, $params, 'exchange-token-mode-before-introspection', ['access-token', 'refresh-token']);
		$this->setBool($config, $params, 'use-access-token-payload-for-user-info');
		$this->setBool($config, $params, 'use-access-token-introspection-for-user-info');
		$this->setBool($config, $params, 'jwt-self-signed-jwk-header-supported');
		$this->setStringIfPresent($config, $params, 'ocis-routing-policy-claim');
		$this->setStringIfPresent($config, $params, 'ocis-routing-policy-cookie');
		$this->setStringIfPresent($config, $params, 'ocis-routing-policy-cookie-directives');
		$this->setJsonIfPresent($config, $params, 'provider-params');
		$this->setJsonIfPresent($config, $params, 'auth-params');

		$autoProvision = [];
		$autoProvision['enabled'] = $this->bool($params, 'auto-provision-enabled');
		$groups = $this->parseList((string)($params['auto-provision-groups'] ?? ''));
		if ($groups !== []) {
			$autoProvision['groups'] = $groups;
		}
		$this->setStringIfPresent($autoProvision, $params, 'auto-provision-email-claim', 'email-claim');
		$this->setStringIfPresent($autoProvision, $params, 'auto-provision-display-name-claim', 'display-name-claim');
		$this->setStringIfPresent($autoProvision, $params, 'auto-provision-picture-claim', 'picture-claim');
		$this->setStringIfPresent($autoProvision, $params, 'auto-provision-provisioning-claim', 'provisioning-claim');
		$this->setStringIfPresent($autoProvision, $params, 'auto-provision-provisioning-attribute', 'provisioning-attribute');
		$autoProvision['update'] = [
			'enabled' => $this->bool($params, 'auto-provision-update-enabled'),
		];
		$config['auto-provision'] = $autoProvision;

		return $config;
	}

	private function requiredString(array $params, string $key, string $message): string {
		$value = $this->string($params, $key);
		if ($value === '') {
			throw new \InvalidArgumentException($message);
		}
		return $value;
	}

	private function string(array $params, string $key, string $default = ''): string {
		return \trim((string)($params[$key] ?? $default));
	}

	private function bool(array $params, string $key): bool {
		$value = $params[$key] ?? false;
		return $value === true || $value === 'true' || $value === '1' || $value === 1 || $value === 'on';
	}

	private function setBool(array &$config, array $params, string $key): void {
		$config[$key] = $this->bool($params, $key);
	}

	private function setStringIfPresent(array &$config, array $params, string $sourceKey, ?string $targetKey = null): void {
		$value = $this->string($params, $sourceKey);
		if ($value !== '') {
			$config[$targetKey ?? $sourceKey] = $value;
		}
	}

	private function setListIfPresent(array &$config, array $params, string $key): void {
		$list = $this->parseList((string)($params[$key] ?? ''));
		if ($list !== []) {
			$config[$key] = $list;
		}
	}

	private function setEnumIfPresent(array &$config, array $params, string $key, array $allowed): void {
		$value = $this->string($params, $key);
		if ($value === '') {
			return;
		}
		if (!\in_array($value, $allowed, true)) {
			throw new \InvalidArgumentException($this->l10n()->t('Invalid value for %s', [$this->fieldLabel($key)]));
		}
		$config[$key] = $value;
	}

	/**
	 * Beschriftung des Felds, wie sie auf der Seite steht - die Meldung nennt
	 * sonst den internen Schlüssel ("provider-params").
	 */
	private function fieldLabel(string $key): string {
		return match ($key) {
			'provider-params' => $this->l10n()->t('Provider params JSON'),
			'auth-params' => $this->l10n()->t('Auth params JSON'),
			'exchange-token-mode-before-introspection' => $this->l10n()->t('Token exchange mode'),
			default => $key,
		};
	}

	private function setJsonIfPresent(array &$config, array $params, string $key): void {
		$value = \trim((string)($params[$key] ?? ''));
		if ($value === '') {
			return;
		}
		$decoded = \json_decode($value, true);
		if (\json_last_error() !== JSON_ERROR_NONE || !\is_array($decoded)) {
			throw new \InvalidArgumentException($this->l10n()->t('%s must be a valid JSON object or array', [$this->fieldLabel($key)]));
		}
		$config[$key] = $decoded;
	}

	private function parseList(string $value): array {
		$items = \preg_split('/[\r\n, ]+/', $value) ?: [];
		return \array_values(\array_filter(\array_map('trim', $items), static function ($item) {
			return $item !== '';
		}));
	}

	private function listToText($value): string {
		return \is_array($value) ? \implode("\n", $value) : (string)$value;
	}

	private function encodeJsonField($value): string {
		if (!\is_array($value) || $value === []) {
			return '';
		}
		return \json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	private function encodeConfig(array $config): string {
		return \json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}
