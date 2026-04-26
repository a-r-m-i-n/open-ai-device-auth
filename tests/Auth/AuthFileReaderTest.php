<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Auth;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;

final class AuthFileReaderTest extends TestCase
{
    public function testItReadsAValidAuthFile(): void
    {
        $path = $this->createAuthFile([
            'auth_mode' => 'chatgpt',
            'tokens' => [
                'id_token' => 'id-token',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'account_id' => 'account-123',
            ],
            'last_refresh' => '2026-04-26T10:00:00.000000Z',
        ]);

        $authFile = (new AuthFileReader())->read($path);

        self::assertSame('chatgpt', $authFile->authMode);
        self::assertSame('access-token', $authFile->accessToken);
        self::assertSame('refresh-token', $authFile->refreshToken);
        self::assertSame('account-123', $authFile->accountId);
    }

    public function testItFailsWhenRefreshTokenIsMissing(): void
    {
        $path = $this->createAuthFile([
            'auth_mode' => 'chatgpt',
            'tokens' => [
                'id_token' => 'id-token',
                'access_token' => 'access-token',
                'account_id' => 'account-123',
            ],
            'last_refresh' => '2026-04-26T10:00:00.000000Z',
        ]);

        $this->expectException(OpenAiDeviceAuthException::class);
        $this->expectExceptionMessage('tokens.refresh_token');

        (new AuthFileReader())->read($path);
    }

    public function testItFailsWhenAccessTokenIsMissing(): void
    {
        $path = $this->createAuthFile([
            'auth_mode' => 'chatgpt',
            'tokens' => [
                'id_token' => 'id-token',
                'refresh_token' => 'refresh-token',
                'account_id' => 'account-123',
            ],
            'last_refresh' => '2026-04-26T10:00:00.000000Z',
        ]);

        $this->expectException(OpenAiDeviceAuthException::class);
        $this->expectExceptionMessage('tokens.access_token');

        (new AuthFileReader())->read($path);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAuthFile(array $payload): string
    {
        $path = sys_get_temp_dir() . '/open-ai-device-auth-reader-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
