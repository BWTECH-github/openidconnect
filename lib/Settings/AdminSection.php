<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */

namespace OCA\OpenIdConnect\Settings;

use OCP\IL10N;
use OCP\Settings\ISection;

class AdminSection implements ISection {
	public function __construct(
		private readonly IL10N $l,
	) {
	}

	public function getID() {
		return 'openidconnect';
	}

	public function getName() {
		return $this->l->t('OpenID Connect');
	}

	public function getPriority() {
		return 86;
	}

	public function getIconName() {
		return 'password';
	}
}
