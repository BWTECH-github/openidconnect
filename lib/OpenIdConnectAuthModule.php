<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Miroslav Bauer <Miroslav.Bauer@cesnet.cz>
 *
 * @copyright Copyright (c) 2022, ownCloud GmbH
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

use Jumbojett\OpenIDConnectClientException;
use OC\User\LoginException;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\Authentication\IAuthModule;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Used in case owncloud.online acts as relying party.
 * Mobile clients, desktop clients and phoenix will send an access token which
 * has been issued by the connected OpenID Connect Provider.
 */
class OpenIdConnectAuthModule implements IAuthModule {
	private readonly Logger $logger;

	public function __construct(
		private readonly IUserManager $manager,
		ILogger $logger,
		private readonly ICacheFactory $cacheFactory,
		private readonly UserLookupService $lookupService,
		private readonly Client $client,
		private readonly AutoProvisioningService $autoProvisioningService,
	) {
		$this->logger = new Logger($logger);
	}

	/**
	 * @throws LoginException
	 */
	#[\Override]
	public function auth(IRequest $request): ?IUser {
		$authHeader = $request->getHeader('Authorization');
		if (!\is_string($authHeader) || $authHeader === '') {
			return null;
		}

		if (stripos($authHeader, 'bearer ') === 0) {
			$bearerToken = \substr($authHeader, 7);
			return $this->authToken('Bearer', $bearerToken);
		}

		if (stripos($authHeader, 'pop ') === 0) {
			$bearerToken = \substr($authHeader, 4);
			return $this->authToken('PoP', $bearerToken);
		}

		return null;
	}

	/**
	 * @throws LoginException
	 */
	public function authToken(string $type, string $token): ?IUser {
		$this->logger->debug("OpenIdConnectAuthModule::authToken type=$type");
		try {
			if ($this->client->getOpenIdConfig() === null) {
				return null;
			}
			// 1. verify token
			$expiry = $this->verifyToken($token);

			// 2. verify expiry
			if ($expiry) {
				$expiring = $expiry - \time();
				if ($expiring < 0) {
					$this->logger->debug("OpenID Connect token expired at $expiry");
					throw new LoginException('OpenID Connect token expired');
				}
			}

			// 3. get user
			$user = $this->getUserResource($token);
			if ($user) {
				$this->updateCache($token, $user, $expiry);
				return $user;
			}
			$this->logger->debug('OpenIdConnectAuthModule::authToken : no user retrieved from token');
			return null;
		} catch (OpenIDConnectClientException $ex) {
			$this->logger->logException($ex);
			return null;
		}
	}

	/**
	 * @codeCoverageIgnore
	 */
	#[\Override]
	public function getUserPassword(IRequest $request): string {
		return '';
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	private function verifyToken(string $token) {
		$cache = $this->getCache();
		$userInfo = $cache->get($token);
		if ($userInfo) {
			return $userInfo['exp'];
		}

		return $this->client->verifyToken($token);
	}

	private function getCache(): ICache {
		// TODO: needs cleanup and consolidation with SessionVerifier usage of the cache
		return $this->cacheFactory->create('oca.openid-connect.2');
	}

	private function getUserResource($bearerToken): ?IUser {
		$cache = $this->getCache();
		$userInfo = $cache->get($bearerToken);
		if ($userInfo) {
			$this->logger->debug('OpenIdConnectAuthModule::getUserResource from cache: ' . \json_encode($userInfo));
			return $this->manager->get($userInfo['uid']);
		}

		$this->client->setAccessToken($bearerToken);
		$userInfo = $this->client->getUserInfo();
		$this->logger->debug('OpenIdConnectAuthModule::getUserResource from cache: ' . \json_encode($userInfo));
		if ($userInfo === null) {
			return null;
		}
		$user = $this->lookupService->lookupUser($userInfo);

		if ($this->autoProvisioningService->autoUpdateEnabled()) {
			$this->autoProvisioningService->updateAccountInfo($user, $userInfo);
		}

		return $user;
	}

	private function updateCache(string $bearerToken, IUser $user, int $expiry): void {
		$cache = $this->getCache();
		$cache->set($bearerToken, [
			'uid' => $user->getUID(),
			'exp' => $expiry,
		]);
	}
}
