<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license GPL-2.0-only
 */

namespace OCA\OpenIdConnect;

use RuntimeException;

function loadComposerDependencies(): void {
	$autoload = __DIR__ . '/../vendor/autoload.php';
	if (\is_file($autoload)) {
		require_once $autoload;
	}
}

function assertComposerDependencies(): void {
	if (!\class_exists(\Jumbojett\OpenIDConnectClient::class)) {
		throw new RuntimeException(
			'OpenID Connect dependencies are missing. Run composer install --no-dev --optimize-autoloader in the openidconnect app directory.'
		);
	}
}

function getConfiguredOpenIdConnectSettings(\OCP\IConfig $config): ?array {
	$configRaw = $config->getAppValue(Application::APPID, 'openid-connect', null);
	if ($configRaw) {
		$decoded = \json_decode($configRaw, true);
		if (\json_last_error() === JSON_ERROR_NONE && \is_array($decoded)) {
			return $decoded;
		}
	}

	$systemConfig = $config->getSystemValue('openid-connect', null);
	return \is_array($systemConfig) ? $systemConfig : null;
}
