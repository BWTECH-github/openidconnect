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
namespace OCA\OpenIdConnect;

// Die Elternklasse kommt aus dem vendor-Verzeichnis der App (wie in Client.php)
require_once __DIR__ . '/bootstrap.php';
loadComposerDependencies();

use Jumbojett\OpenIDConnectClientException;

/**
 * Eine Anfrage an den Anbieter (IdP) ist gescheitert: nicht erreichbar,
 * Fehlerstatus oder keine brauchbare Antwort. Das ist kein Urteil über ein
 * Token, sondern heißt nur, dass der Anbieter gerade nicht gefragt werden kann.
 *
 * Erbt von OpenIDConnectClientException, weil die Bibliothek genau das bei
 * einem Transportfehler wirft - die catch-Blöcke der App sind darauf gebaut.
 * Die Meldung ist bewusst allgemein und nennt keine Adresse; die Ursache steht
 * einmal im Protokoll (Client::fetchURL()).
 */
class ProviderRequestException extends OpenIDConnectClientException {
	public const MESSAGE = 'The OpenID Connect provider could not be reached or did not return a usable response.';

	public function __construct(?\Throwable $previous = null) {
		parent::__construct(self::MESSAGE, 0, $previous);
	}
}
