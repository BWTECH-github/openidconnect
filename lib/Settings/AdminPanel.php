<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Settings;

use OCP\Settings\ISettings;
use OCP\Template;
use OCP\Util;

class AdminPanel implements ISettings {
	public function getPanel() {
		Util::addScript('openidconnect', 'admin');
		Util::addStyle('openidconnect', 'admin');

		return new Template('openidconnect', 'admin');
	}

	public function getSectionID() {
		return 'openidconnect';
	}

	public function getPriority() {
		return 50;
	}
}
