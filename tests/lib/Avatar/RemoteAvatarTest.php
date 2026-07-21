<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Test\Avatar;

use OC\Avatar\RemoteAvatar;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Files\SimpleFS\InMemoryFile;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RemoteAvatarTest extends TestCase {
	private const CLOUD_ID = 'user@https://remote.example.com';

	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private ICloudIdManager&MockObject $cloudIdManager;
	private IClientService&MockObject $clientService;
	private RemoteAvatar $avatar;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$cloudId = $this->createMock(ICloudId::class);
		$cloudId->method('getUser')->willReturn('user');
		$cloudId->method('getRemote')->willReturn('https://remote.example.com');
		$cloudId->method('getDisplayId')->willReturn('user@remote.example.com');

		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->cloudIdManager->method('resolveCloudId')
			->with(self::CLOUD_ID)
			->willReturn($cloudId);
		$this->overwriteService(ICloudIdManager::class, $this->cloudIdManager);

		$this->clientService = $this->createMock(IClientService::class);
		$this->overwriteService(IClientService::class, $this->clientService);

		$this->avatar = new RemoteAvatar(self::CLOUD_ID, $this->config, $this->logger);
	}

	/**
	 * Stubs the client returned by IClientService::newClient() to respond to
	 * a single GET request, optionally asserting the requested URL/options.
	 *
	 * @param string|resource|false $body
	 */
	private function mockRemoteClient(string $contentType, $body, ?string $expectedUrl = null, ?array $expectedOptions = null): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getHeader')->with('Content-Type')->willReturn($contentType);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$matcher = $client->expects(self::once())->method('get');
		if ($expectedUrl !== null) {
			$matcher->with($expectedUrl, $expectedOptions ?? self::anything());
		}
		$matcher->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);
	}

	public function testExists(): void {
		self::assertTrue($this->avatar->exists());
	}

	public function testGetDisplayName(): void {
		self::assertSame('user@remote.example.com', $this->avatar->getDisplayName());
	}

	public function testSetIsANoop(): void {
		$this->avatar->set('some-data');
		$this->addToAssertionCount(1);
	}

	public function testGetFileFetchesTheAvatarFromTheRemoteInstance(): void {
		$this->config->method('getSystemValueBool')
			->with('sharing.federation.allowSelfSignedCertificates', false)
			->willReturn(false);

		$this->mockRemoteClient(
			'image/png',
			'png-bytes',
			'https://remote.example.com/index.php/avatar/user/64',
			['verify' => true],
		);

		$file = $this->avatar->getFile(64);
		self::assertInstanceOf(InMemoryFile::class, $file);
		self::assertSame('avatar.png', $file->getName());
		self::assertSame('png-bytes', $file->getContent());
	}

	public function testGetFileRequestsTheDarkVariant(): void {
		$this->mockRemoteClient(
			'image/png',
			'png-bytes',
			'https://remote.example.com/index.php/avatar/user/512/dark',
		);

		$this->avatar->getFile(512, true);
	}

	public function testGetFileThrowsOnUnexpectedContentType(): void {
		$this->mockRemoteClient('text/html', '<html></html>');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unknown filetype');

		$this->avatar->getFile(64);
	}

	public function testUserChangedIsANoop(): void {
		$this->avatar->userChanged('displayName', 'old', 'new');
		$this->addToAssertionCount(1);
	}

	public function testIsCustomAvatar(): void {
		self::assertTrue($this->avatar->isCustomAvatar());
	}
}
