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
 */
namespace OCA\OpenIdConnect\Service;

use OC\User\LoginException;

/**
 * Gescheiterte Kontozuordnung mit Ursache.
 *
 * Seit 3.0.0 sieht die angemeldete Person nur noch eine übersetzte Seite
 * ("kein Konto"), nicht mehr den englischen Text der Ausnahme. Damit die
 * Administration die Ursache trotzdem auseinanderhalten kann - ein Tippfehler
 * in allowed-user-backends sperrt alle aus, ein unbekanntes Konto nur eine
 * Person -, schreibt der Controller logText() ins Protokoll. Werte aus den
 * Claims (E-Mail-Adresse, Benutzer-ID) stehen dort nie; Claim- und
 * Backend-Namen sind Konfiguration und kommen als $detail mit.
 *
 * Die Nachricht selbst bleibt wie bisher (mit Wert) - sie erreicht weder
 * Protokoll noch Oberfläche, nur Aufrufer wie Tests.
 */
class AccountLoginException extends LoginException {
	public const CLAIM_MISSING = 1001;
	public const ACCOUNT_UNKNOWN = 1002;
	public const ACCOUNT_NOT_UNIQUE = 1003;
	public const BACKEND_NOT_ALLOWED = 1004;
	public const PROVISIONING_DISABLED = 1005;
	public const PROVISIONING_CLAIM_MISSING = 1006;
	public const ACCOUNT_CREATION_FAILED = 1007;

	public function __construct(string $message, int $code, private readonly string $detail = '') {
		parent::__construct($message, $code);
	}

	/**
	 * Protokolltext ohne Werte aus den Claims.
	 */
	public function logText(): string {
		$zusatz = $this->detail !== '' ? " ({$this->detail})" : '';
		return match ($this->getCode()) {
			self::CLAIM_MISSING => 'the configured claim is missing in the user info' . $zusatz,
			self::ACCOUNT_UNKNOWN => 'no account matches the identity and auto provisioning is off',
			self::ACCOUNT_NOT_UNIQUE => 'several accounts match the identity',
			self::BACKEND_NOT_ALLOWED => 'the account belongs to a user backend that is not in allowed-user-backends' . $zusatz,
			self::PROVISIONING_DISABLED => 'auto provisioning is disabled',
			self::PROVISIONING_CLAIM_MISSING => 'the claim or value required for auto provisioning is missing' . $zusatz,
			self::ACCOUNT_CREATION_FAILED => 'creating the account failed',
			default => 'no usable account for the identity',
		};
	}
}
