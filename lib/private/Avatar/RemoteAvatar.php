<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace OC\Avatar;

use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Files\SimpleFS\InMemoryFile;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class RemoteAvatar extends Avatar {
	private ICloudId $cloudId;

	public function __construct(
		protected string $userId,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
		parent::__construct($config, $logger);

		$cloudIdManager = \OCP\Server::get(ICloudIdManager::class);
		$this->cloudId = $cloudIdManager->resolveCloudId($userId);
	}

	#[\Override]
	public function exists(): bool {
		return true;
	}

	#[\Override]
	public function getDisplayName(): string {
		return $this->cloudId->getDisplayId();
	}

	/**
	 * Setting avatars isn't implemented for remote accounts
	 */
	public function set($data): void {
	}

	/**
	 * Removing avatars isn't implemented for remote accounts
	 */
	public function remove(bool $silent = false): void {
	}

	#[\Override]
	public function getFile(int $size, bool $darkTheme = false): ISimpleFile {
		$url = rtrim($this->cloudId->getRemote(), '/') . '/index.php/avatar/' . rawurlencode($this->cloudId->getUser()) . '/' . $size;
		if ($darkTheme) {
			$url .= '/dark';
		}

		$clientService = \OCP\Server::get(IClientService::class);
		$client = $clientService->newClient();
		$response = $client->get($url, [
			'verify' => !$this->config->getSystemValueBool('sharing.federation.allowSelfSignedCertificates', false)
		]);

		$contentType = $response->getHeader('Content-Type');
		if ($contentType !== 'image/png') {
			throw new \Exception('Unknown filetype');
		}

		$avatar = $response->getBody();
		if (is_resource($avatar)) {
			$avatar = stream_get_contents($avatar);
			if ($avatar === false) {
				throw new \Exception('Failed to fetch remote avatar');
			}
		}

		return new InMemoryFile('avatar.png', $avatar);
	}

	/**
	 * Handling user changes isn't implemented for remote accounts
	 */
	#[\Override]
	public function userChanged(string $feature, $oldValue, $newValue): void {
	}

	#[\Override]
	public function isCustomAvatar(): bool {
		return true;
	}
}
