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

namespace OCA\OpenIdConnect;

use OCP\ILogger;

class Logger implements ILogger {
	private const APP_CONTEXT = 'OpenID';

	public function __construct(
		private readonly ILogger $logger,
	) {
	}

	#[\Override]
	public function emergency($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->emergency($message, $context);
	}

	#[\Override]
	public function alert($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->alert($message, $context);
	}

	#[\Override]
	public function critical($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->critical($message, $context);
	}

	#[\Override]
	public function error($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->error($message, $context);
	}

	#[\Override]
	public function warning($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->warning($message, $context);
	}

	#[\Override]
	public function notice($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->notice($message, $context);
	}

	#[\Override]
	public function info($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->info($message, $context);
	}

	#[\Override]
	public function debug($message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->debug($message, $context);
	}

	#[\Override]
	public function log($level, $message, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->log($level, $message, $context);
	}

	#[\Override]
	public function logException($exception, array $context = []): void {
		$context['app'] = self::APP_CONTEXT;
		$this->logger->logException($exception, $context);
	}
}
