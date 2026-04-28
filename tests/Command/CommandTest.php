<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Auth\AuthFileWriter;
use Armin\OpenAiDeviceAuth\Auth\TokenPayloadDecoder;
use Armin\OpenAiDeviceAuth\Command\LoginCommand;
use Armin\OpenAiDeviceAuth\Command\RefreshCommand;
use Armin\OpenAiDeviceAuth\Command\UsageCommand;
use Armin\OpenAiDeviceAuth\Http\DeviceCodeClient;
use Armin\OpenAiDeviceAuth\Http\DeviceCodePoller;
use Armin\OpenAiDeviceAuth\Http\RefreshTokenClient;
use Armin\OpenAiDeviceAuth\Http\TokenExchanger;
use Armin\OpenAiDeviceAuth\Http\UsageClient;
use Armin\OpenAiDeviceAuth\Model\AuthFile;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CommandTest extends TestCase
{
    public function testLoginCommandWritesAuthFile(): void
    {
        $command = new LoginCommand(
            new DeviceCodeClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'device_auth_id' => 'device-1',
                    'user_code' => 'ABCD',
                    'interval' => '0',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new DeviceCodePoller(new MockHttpClient([
                new MockResponse(json_encode([
                    'authorization_code' => 'auth-code',
                    'code_verifier' => 'verifier',
                    'code_challenge' => 'challenge',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenExchanger(new MockHttpClient([
                new MockResponse(json_encode([
                    'id_token' => $this->createJwt('account-123'),
                    'access_token' => 'access-token',
                    'refresh_token' => 'refresh-token',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenPayloadDecoder(),
            new AuthFileWriter()
        );

        $path = sys_get_temp_dir() . '/open-ai-device-auth-login-' . uniqid('', true) . '.json';
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path]));
        self::assertStringContainsString('Authentication successful', $tester->getDisplay());
        self::assertFileExists($path);
    }

    public function testLoginCommandSupportsShortAuthFileAlias(): void
    {
        $command = new LoginCommand(
            new DeviceCodeClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'device_auth_id' => 'device-1',
                    'user_code' => 'ABCD',
                    'interval' => '0',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new DeviceCodePoller(new MockHttpClient([
                new MockResponse(json_encode([
                    'authorization_code' => 'auth-code',
                    'code_verifier' => 'verifier',
                    'code_challenge' => 'challenge',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenExchanger(new MockHttpClient([
                new MockResponse(json_encode([
                    'id_token' => $this->createJwt('account-123'),
                    'access_token' => 'access-token',
                    'refresh_token' => 'refresh-token',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenPayloadDecoder(),
            new AuthFileWriter()
        );

        $path = sys_get_temp_dir() . '/open-ai-device-auth-login-' . uniqid('', true) . '.json';
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['-a' => $path]));
        self::assertFileExists($path);
    }

    public function testRefreshCommandUpdatesTheAuthFile(): void
    {
        $path = $this->createAuthJson('old-access', 'old-refresh', 'old-account');
        $command = new RefreshCommand(
            new AuthFileReader(),
            new RefreshTokenClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'id_token' => $this->createJwt('new-account'),
                    'access_token' => 'new-access',
                    'refresh_token' => 'new-refresh',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenPayloadDecoder(),
            new AuthFileWriter()
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path]));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('new-access', $data['tokens']['access_token']);
        self::assertSame('new-refresh', $data['tokens']['refresh_token']);
        self::assertSame('new-account', $data['tokens']['account_id']);
    }

    public function testRefreshCommandSupportsShortAuthFileAlias(): void
    {
        $path = $this->createAuthJson('old-access', 'old-refresh', 'old-account');
        $command = new RefreshCommand(
            new AuthFileReader(),
            new RefreshTokenClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'id_token' => $this->createJwt('new-account'),
                    'access_token' => 'new-access',
                    'refresh_token' => 'new-refresh',
                ], JSON_THROW_ON_ERROR)),
            ])),
            new TokenPayloadDecoder(),
            new AuthFileWriter()
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['-a' => $path]));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('new-access', $data['tokens']['access_token']);
    }

    public function testUsageCommandRendersTextOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 25,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
            $this->fixedNow('2026-04-26T10:37:45Z')
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'text']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('ChatGPT Usage', $display);
        self::assertStringContainsString($path, $display);
        self::assertStringContainsString('Primary Left', $display);
        self::assertStringContainsString('75.00%', $display);
        self::assertStringContainsString('25.00%', $display);
        self::assertStringContainsString('180 minutes (3.0 hours)', $display);
        self::assertStringContainsString('Primary Resets', $display);
        self::assertStringContainsString('2026-04-26T12:00:00Z', $display);
        self::assertStringContainsString('Primary Resets In', $display);
        self::assertStringContainsString('1h 22m', $display);
    }

    public function testUsageCommandRendersSecondaryResetCountdown(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 25,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                    'secondary' => [
                        'usedPercent' => 10,
                        'windowDurationMins' => 10080,
                        'resetsAt' => '2026-04-30T18:15:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
            $this->fixedNow('2026-04-26T10:00:00Z')
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'text']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Secondary Left', $display);
        self::assertStringContainsString('90.00%', $display);
        self::assertStringContainsString('10080 minutes (7.0 days)', $display);
        self::assertStringContainsString('Secondary Resets In', $display);
        self::assertStringContainsString('4d 8h 15m', $display);
    }

    public function testUsageCommandShowsZeroMinutesForPastOrSubMinuteResets(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 25,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T10:00:30Z',
                    ],
                    'secondary' => [
                        'usedPercent' => 10,
                        'windowDurationMins' => 10080,
                        'resetsAt' => '2026-04-26T09:59:59Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
            $this->fixedNow('2026-04-26T10:00:00Z')
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path]));
        $display = $tester->getDisplay();
        self::assertSame(2, substr_count($display, '0m'));
    }

    public function testUsageCommandRendersJsonOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 25,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]))
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'json']));
        self::assertJson($tester->getDisplay());
        self::assertStringContainsString('"primary"', $tester->getDisplay());
        self::assertStringNotContainsString('Resets In', $tester->getDisplay());
    }

    public function testUsageCommandRendersBarsOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 69,
                        'windowDurationMins' => 300,
                        'resetsAt' => '2026-04-26T12:15:00Z',
                    ],
                    'secondary' => [
                        'usedPercent' => 10,
                        'windowDurationMins' => 10080,
                        'resetsAt' => '2026-05-01T21:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
            $this->fixedNow('2026-04-26T10:00:00Z')
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'bars']));
        $display = $tester->getDisplay();
        self::assertStringContainsString($path, $display);
        self::assertStringContainsString('5h', $display);
        self::assertStringContainsString('31% left (reset in 2h 15min)', $display);
        self::assertStringContainsString('7d', $display);
        self::assertStringContainsString('90% left (reset in 5d 11h)', $display);
        self::assertStringContainsString('█', $display);
        self::assertStringContainsString('░', $display);
    }

    public function testUsageCommandSupportsShortOptionAliases(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 25,
                        'windowDurationMins' => 300,
                        'resetsAt' => '2026-04-26T12:15:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
            $this->fixedNow('2026-04-26T10:00:00Z')
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['-a' => $path, '-f' => 'bars']));
        self::assertStringContainsString($path, $tester->getDisplay());
        self::assertStringContainsString('5h', $tester->getDisplay());
        self::assertStringContainsString('75% left (reset in 2h 15min)', $tester->getDisplay());
    }

    public function testUsageCommandHighlightsWarningThresholdsInBarsOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 80,
                        'windowDurationMins' => 300,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]))
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'bars'], ['decorated' => true]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\033\\[(?:38;5;214|33)m20% left\033\\[39m/', $display);
        self::assertMatchesRegularExpression('/\033\\[(?:38;5;214|33)m█+/', $display);
    }

    public function testUsageCommandHighlightsCriticalThresholdsInBarsOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 92,
                        'windowDurationMins' => 300,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]))
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'bars'], ['decorated' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString("\033[31m8% left\033[39m", $display);
        self::assertMatchesRegularExpression('/\033\\[31m█+/', $display);
    }

    public function testUsageCommandHighlightsWarningThresholdsInTextOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 75,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]))
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'text'], ['decorated' => true]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\033\\[(?:38;5;214|33)m25\\.00%\033\\[39m/', $display);
        self::assertMatchesRegularExpression('/\033\\[(?:38;5;214|33)m75\\.00%\033\\[39m/', $display);
    }

    public function testUsageCommandHighlightsCriticalThresholdsInTextOutput(): void
    {
        $path = $this->createAuthJson('access-token', 'refresh-token', 'account-123');
        $command = new UsageCommand(
            new AuthFileReader(),
            new UsageClient(new MockHttpClient([
                new MockResponse(json_encode([
                    'primary' => [
                        'usedPercent' => 95,
                        'windowDurationMins' => 180,
                        'resetsAt' => '2026-04-26T12:00:00Z',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]))
        );

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--auth-file' => $path, '--format' => 'text'], ['decorated' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString("\033[31m5.00%\033[39m", $display);
        self::assertStringContainsString("\033[31m95.00%\033[39m", $display);
    }

    public function testRefreshCommandUsesDefaultAuthFilePath(): void
    {
        $application = new Application();
        $application->add(new RefreshCommand());
        $tester = new CommandTester($application->find('refresh'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Unable to read auth file at ./auth.json.', $tester->getDisplay());
    }

    public function testUsageCommandUsesDefaultAuthFilePath(): void
    {
        $application = new Application();
        $application->add(new UsageCommand());
        $tester = new CommandTester($application->find('usage'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Unable to read auth file at ./auth.json.', $tester->getDisplay());
    }

    private function createJwt(string $accountId): string
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => $accountId,
            ],
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return sprintf('%s.%s.', $header, $payload);
    }

    private function createAuthJson(string $accessToken, string $refreshToken, string $accountId): string
    {
        $path = sys_get_temp_dir() . '/open-ai-device-auth-command-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode([
            'auth_mode' => 'chatgpt',
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => $this->createJwt($accountId),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'account_id' => $accountId,
            ],
            'last_refresh' => '2026-04-26T10:00:00.000000Z',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function fixedNow(string $now): Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }
}
