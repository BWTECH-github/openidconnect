<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Ilja Neumann <ineumann@owncloud.com>
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

namespace OCA\OpenIdConnect\Service;

use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\User\NotPermittedActionException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;

class AutoProvisioningService {
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IAvatarManager $avatarManager,
		private readonly IClientService $clientService,
		private readonly ILogger $logger,
		private readonly Client $client,
		private readonly EventDispatcher $eventDispatcher,
		private readonly ISecureRandom $secureRandom,
	) {
	}

	/**
	 * @throws LoginException
	 * @throws NotPermittedActionException
	 * @throws \Exception
	 */
	public function createUser($userInfo): IUser {
		// Modified by BW-Tech GmbH on 2026-09-17: cause codes for the log, see
		// AccountLoginException.
		if (!$this->autoProvisioningEnabled()) {
			throw new AccountLoginException('Auto provisioning is disabled.', AccountLoginException::PROVISIONING_DISABLED);
		}
		$attribute = $this->client->getIdentityClaim();
		$emailOrUserId = $userInfo->$attribute ?? null;
		if (!$emailOrUserId) {
			throw new AccountLoginException("Configured attribute $attribute is not known.", AccountLoginException::CLAIM_MISSING, $attribute);
		}
		$userId = $this->client->mode() === 'email' ? $this->generateUserId() : $emailOrUserId;

		$config = $this->client->getAutoProvisionConfig();
		$provisioningClaim = $config['provisioning-claim'] ?? null;
		if ($provisioningClaim) {
			$this->logger->debug('ProvisioningClaim is defined for auto-provision', ['claim' => $provisioningClaim]);
			$provisioningAttribute = $config['provisioning-attribute'] ?? null;

			if (!\property_exists($userInfo, $provisioningClaim) || !\is_array($userInfo->$provisioningClaim)) {
				throw new AccountLoginException('Required provisioning attribute is not found.', AccountLoginException::PROVISIONING_CLAIM_MISSING, $provisioningClaim);
			}

			if (!\in_array($provisioningAttribute, $userInfo->$provisioningClaim, true)) {
				throw new AccountLoginException('Required provisioning attribute is not found.', AccountLoginException::PROVISIONING_CLAIM_MISSING, $provisioningClaim);
			}
		}

		$user = $this->userManager->createUser($userId, $this->generatePassword());
		if (!$user) {
			throw new AccountLoginException("Unable to create user $userId", AccountLoginException::ACCOUNT_CREATION_FAILED);
		}
		$user->setEnabled(true);

		$groups = $config['groups'] ?? [];
		foreach ($groups as $group) {
			$g = $this->groupManager->get($group);
			if ($g) {
				$g->addUser($user);
			}
		}

		$this->updateAccountInfo($user, $userInfo, true);

		$pictureUrl = $this->client->getUserPicture($userInfo);
		if ($pictureUrl) {
			try {
				$resource = $this->downloadPicture($pictureUrl);
				$av = $this->avatarManager->getAvatar($user->getUID());
				$av->set($resource);
			} catch (\Exception $ex) {
				$this->logger->logException($ex, [
					'message' => "Error setting profile picture $pictureUrl",
				]);
			}
		}

		return $user;
	}

	/**
	 * @throws LoginException
	 * @throws NotPermittedActionException
	 */
	public function updateAccountInfo(IUser $user, $userInfo, bool $force = false): void {
		if (!($this->autoUpdateEnabled() || $force)) {
			throw new LoginException('Account auto-update is disabled.');
		}
		# email is only changed in case the mode is not `email`
		if ($force || $this->client->mode() !== 'email') {
			if ($force || $user->canChangeMailAddress()) {
				$currentEmail = $this->client->getUserEmail($userInfo);
				if ($currentEmail && $currentEmail !== $user->getEMailAddress()) {
					$this->logger->debug('AutoProvisioningService: setting e-mail to ' . $currentEmail);
					$user->setEMailAddress($currentEmail);
				}
			}
		}

		if ($force || $user->canChangeDisplayName()) {
			$currentDN = $this->client->getUserDisplayName($userInfo);
			if ($currentDN && $currentDN !== $user->getDisplayName()) {
				$this->logger->debug('AutoProvisioningService: setting display name to ' . $currentDN);
				$user->setDisplayName($currentDN);
			}
		}
	}

	public function autoProvisioningEnabled(): bool {
		return (bool)($this->client->getAutoProvisionConfig()['enabled'] ?? false);
	}

	public function autoUpdateEnabled(): bool {
		return (bool)($this->client->getAutoUpdateConfig()['enabled'] ?? false);
	}

	/**
	 * @throws \Exception
	 */
	protected function downloadPicture(string $pictureUrl): string {
		return $this->clientService->newClient()->get($pictureUrl)->getBody();
	}

	private function generateUserId(): string {
		$random = $this->secureRandom->generate(
			16,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
		);
		return "oidc-user-$random";
	}

	private function generatePassword(): string {
		$event = new GenericEvent();

		$this->eventDispatcher->dispatch($event, 'OCP\User::createPassword');

		if ($event->hasArgument('password')) {
			return $event->getArgument('password');
		}

		return $this->secureRandom->generate(32);
	}
}
