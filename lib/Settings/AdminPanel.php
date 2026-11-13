<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Settings;

use OCP\ICacheFactory;
use OCP\Settings\ISettings;
use OCP\Template;
use OCP\Util;

class AdminPanel implements ISettings {
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
	}

	public function getPanel() {
		Util::addScript('openidconnect', 'admin');
		Util::addStyle('openidconnect', 'admin');

		$template = new Template('openidconnect', 'admin');
		// Ohne verteilten Cache bleibt OpenID Connect aus (Application::boot()).
		$template->assign('cacheMissing', !$this->cacheFactory->isAvailable());
		return $template;
	}

	public function getSectionID() {
		return 'openidconnect';
	}

	public function getPriority() {
		return 50;
	}
}
