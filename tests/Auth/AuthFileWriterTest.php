<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Auth;

use Armin\OpenAiDeviceAuth\Auth\AuthFileWriter;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;
use PHPUnit\Framework\TestCase;

final class AuthFileWriterTest extends TestCase
{
    public function testItWritesTheExpectedJsonStructure(): void
    {
        $path = sys_get_temp_dir() . '/open-ai-device-auth-' . uniqid('', true) . '.json';
        $writer = new AuthFileWriter();

        $writer->write($path, new TokenResponse('id-token', 'access-token', 'refresh-token', 3600), 'account-123');

        $contents = file_get_contents($path);

        self::assertIsString($contents);
        /** @var array<string, mixed> $json */
        $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('chatgpt', $json['auth_mode']);
        self::assertNull($json['OPENAI_API_KEY']);
        self::assertSame('id-token', $json['tokens']['id_token']);
        self::assertSame('access-token', $json['tokens']['access_token']);
        self::assertSame('refresh-token', $json['tokens']['refresh_token']);
        self::assertSame('account-123', $json['tokens']['account_id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T.*Z$/', $json['last_refresh']);
    }
}
