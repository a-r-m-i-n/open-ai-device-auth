<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Auth;

use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Filesystem\Filesystem;

final class AuthFileWriter
{
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem()
    ) {
    }

    public function write(string $path, TokenResponse $tokens, string $accountId): void
    {
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.') {
            $this->filesystem->mkdir($directory);
        }

        $json = json_encode([
            'auth_mode' => 'chatgpt',
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => $tokens->idToken,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'account_id' => $accountId,
            ],
            'last_refresh' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new OpenAiDeviceAuthException('Failed to encode auth.json payload.');
        }

        $this->filesystem->dumpFile($path, $json . PHP_EOL);
    }
}
