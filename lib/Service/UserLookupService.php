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
namespace OCA\OpenIdConnect\Service;

use OC\HintException;
use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCP\IUser;
use OCP\IUserManager;

class UserLookupService {
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly Client $client,
		private readonly AutoProvisioningService $autoProvisioningService,
	) {
	}

	/**
	 * @throws LoginException
	 * @throws HintException
	 */
	public function lookupUser($userInfo): IUser {
		$openIdConfig = $this->client->getOpenIdConfig();
		if ($openIdConfig === null) {
			throw new HintException('Configuration issue in openidconnect app');
		}
		$searchByEmail = $this->client->mode() !== 'userid';
		$attribute = $this->client->getIdentityClaim();
		// Modified by BW-Tech GmbH on 2026-09-17: AccountLoginException carries
		// a cause code (and configuration names) for the log, the message is
		// unchanged.
		if (!\property_exists($userInfo, $attribute)) {
			throw new AccountLoginException("Configured attribute $attribute is not known.", AccountLoginException::CLAIM_MISSING, $attribute);
		}

		if ($searchByEmail) {
			$user = $this->userManager->getByEmail($userInfo->$attribute);
			if (!$user) {
				if ($this->autoProvisioningService->autoProvisioningEnabled()) {
					return $this->autoProvisioningService->createUser($userInfo);
				}

				throw new AccountLoginException("User with {$userInfo->$attribute} is not known.", AccountLoginException::ACCOUNT_UNKNOWN);
			}
			if (\count($user) !== 1) {
				throw new AccountLoginException("{$userInfo->$attribute} is not unique.", AccountLoginException::ACCOUNT_NOT_UNIQUE);
			}
			$this->validUser($user[0]);
			return $user[0];
		}
		$user = $this->userManager->get($userInfo->$attribute);
		if (!$user) {
			if ($this->autoProvisioningService->autoProvisioningEnabled()) {
				return $this->autoProvisioningService->createUser($userInfo);
			}
			throw new AccountLoginException("User {$userInfo->$attribute} is not known.", AccountLoginException::ACCOUNT_UNKNOWN);
		}
		$this->validUser($user);
		return $user;
	}

	private function validUser(IUser $user): void {
		$openIdConfig = $this->client->getOpenIdConfig();
		$allowedUserBackEnds = $openIdConfig['allowed-user-backends'] ?? null;
		if ($allowedUserBackEnds === null) {
			return;
		}
		if (\in_array($user->getBackendClassName(), $allowedUserBackEnds, true)) {
			return;
		}
		throw new AccountLoginException("User is from wrong user backend <{$user->getBackendClassName()}>", AccountLoginException::BACKEND_NOT_ALLOWED, $user->getBackendClassName());
	}
}
