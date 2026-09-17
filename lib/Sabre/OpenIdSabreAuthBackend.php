<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
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
namespace OCA\OpenIdConnect\Sabre;

use OC\User\Session;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class OpenIdSabreAuthBackend implements BackendInterface {
	public const DAV_AUTHENTICATED = Auth::DAV_AUTHENTICATED;

	private readonly Session $userSession;

	/**
	 * @throws \Exception
	 */
	public function __construct(
		private readonly ISession $session,
		IUserSession $userSession,
		private readonly IRequest $request,
		private readonly OpenIdConnectAuthModule $authModule,
		protected readonly string $principalPrefix = 'principals/users/',
	) {
		if (!$userSession instanceof Session) {
			throw new \Exception('We rely on internal implementation!');
		}
		$this->userSession = $userSession;
	}

	/**
	 * Checks whether the user has initially authenticated via DAV.
	 *
	 * This is required for WebDAV clients that resent the cookies even when the
	 * account was changed.
	 *
	 * @see Upstream core issue #13245
	 */
	private function isDavAuthenticated(string $username): bool {
		return $this->session->get(self::DAV_AUTHENTICATED) !== null
			&& $this->session->get(self::DAV_AUTHENTICATED) === $username;
	}

	/**
	 * Validates a Bearer token.
	 *
	 * @return string|false The full principal url, if the token is valid, false otherwise.
	 * @throws \OC\User\LoginException
	 */
	protected function validateBearerToken($type, $token) {
		if ($this->userSession->isLoggedIn()
			&& $this->isDavAuthenticated($this->userSession->getUser()->getUID())) {
			try {
				// verify the bearer token
				$tokenUser = $this->authModule->authToken($type, $token);
				if ($tokenUser === null) {
					return false;
				}

				// setup the user
				$userId = $this->userSession->getUser()->getUID();
				$this->setupFilesystem($userId);
				$this->session->close();
				return $this->principalPrefix . $userId;
			} catch (\Exception) {
				$this->session->close();
				return false;
			}
		}

		$this->setupFilesystem();

		try {
			// we have to go through IUserSession here to login the user properly
			if ($this->userSession->tryAuthModuleLogin($this->request)) {
				$userId = $this->userSession->getUser()->getUID();
				$this->setupFilesystem($userId);
				$this->session->set(self::DAV_AUTHENTICATED, $userId);
				$this->session->close();
				return $this->principalPrefix . $userId;
			}

			$this->session->close();
			return false;
		} catch (\Exception) {
			$this->session->close();
			return false;
		}
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function setupFilesystem(string $userId = ''): void {
		\call_user_func(['OC_Util', 'setupFS'], $userId);
	}

	#[\Override]
	public function check(RequestInterface $request, ResponseInterface $response) {
		[$type, $token] = $this->getToken($request);

		if (!$token) {
			return [false, "No 'Authorization: Bearer' or 'Authorization: PoP' header found. Either the client didn't send one, or the server is mis-configured"];
		}
		$principalUrl = $this->validateBearerToken($type, $token);
		if (!$principalUrl) {
			return [false, 'Bearer/PoP token was incorrect'];
		}

		return [true, $principalUrl];
	}

	#[\Override]
	public function challenge(RequestInterface $request, ResponseInterface $response) {
		// setup realm
		$defaults = new \OC_Defaults();
		$realm = $defaults->getName();

		$response->addHeader('WWW-Authenticate', 'Bearer realm="' . $realm . '"');
		$response->addHeader('WWW-Authenticate', 'PoP realm="' . $realm . '"');
		$response->setStatus(401);
	}

	private function getToken(RequestInterface $request): array {
		$auth = $request->getHeader('Authorization');

		if (!$auth) {
			return [null, null];
		}

		if (stripos($auth, 'bearer ') === 0) {
			return ['Bearer', substr($auth, 7)];
		}

		if (stripos($auth, 'pop ') === 0) {
			return ['Pop', substr($auth, 4)];
		}

		return [null, null];
	}
}
