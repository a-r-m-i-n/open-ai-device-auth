<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Command;

use Armin\OpenAiDeviceAuth\Command\ResetCommand;
use Armin\OpenAiDeviceAuth\Command\ResetCreditsRenderer;
use Armin\OpenAiDeviceAuth\Command\ResetsCommand;
use Armin\OpenAiDeviceAuth\Http\RateLimitResetClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResetCommandTest extends TestCase
{
    private string $authFilePath;

    protected function setUp(): void
    {
        $this->authFilePath = sys_get_temp_dir() . '/open-ai-device-auth-resets-' . uniqid('', true) . '.json';
        file_put_contents($this->authFilePath, json_encode([
            'auth_mode' => 'chatgpt',
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => 'test-id-token',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'account_id' => 'account-123',
            ],
            'last_refresh' => '2026-04-26T10:00:00.000000Z',
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        unlink($this->authFilePath);
    }

    #[DataProvider('listFormats')]
    public function testResetsCommandShowsDetailsAndTheAuthoritativeCount(string $format): void
    {
        $client = $this->clientFor([
            'available_count' => 3,
            'credits' => [[
                'id' => 'credit-1',
                'reset_type' => 'codex_rate_limits',
                'status' => 'available',
                'granted_at' => '2026-06-17T00:00:00Z',
                'expires_at' => '2026-07-17T00:00:00Z',
                'title' => 'Full <info>reset</info>',
                'description' => 'Weekly + 5 hr',
            ]],
        ]);
        $tester = new CommandTester(new ResetsCommand(rateLimitResetClient: $client, resetCreditsRenderer: $this->renderer()));

        self::assertSame(0, $tester->execute(['-a' => $this->authFilePath, '-f' => $format]));
        if ($format === 'json') {
            $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(3, $data['availableCount']);
            self::assertCount(1, $data['credits']);
            self::assertSame('Full <info>reset</info>', $data['credits'][0]['title']);
            self::assertSame('2026-07-17T00:00:00Z', $data['credits'][0]['expiresAt']);
        } else {
            $display = $tester->getDisplay();
            self::assertStringContainsString('Available resets: 3', $display);
            self::assertStringContainsString('credit-1', $display);
            self::assertStringContainsString('available', $display);
            self::assertStringContainsString('Granted: 2026-06-17 00:00:00 UTC (18d 12h ago)', $display);
            self::assertStringContainsString('Expires: 2026-07-17 00:00:00 UTC (in 11d 12h)', $display);
            self::assertStringContainsString('Full <info>reset</info>', $display);
            self::assertStringContainsString('Weekly + 5 hr', $display);
        }
    }

    public static function listFormats(): iterable
    {
        yield 'text' => ['text'];
        yield 'json' => ['json'];
    }

    public function testResetsCommandShowsZeroAndDefaultsToText(): void
    {
        $tester = new CommandTester(new ResetsCommand(rateLimitResetClient: $this->clientFor(['available_count' => 0, 'credits' => []])));

        self::assertSame(0, $tester->execute(['--auth-file' => $this->authFilePath]));
        self::assertStringContainsString('Available resets: 0', $tester->getDisplay());
        self::assertStringContainsString('No reset credit details', $tester->getDisplay());
    }

    #[DataProvider('outcomes')]
    public function testResetCommandRendersEachOutcome(string $code, int $exitCode, string $message, string $format): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($code, &$requests): MockResponse {
            ++$requests;
            self::assertSame('POST', $method);
            self::assertSame(['redeem_request_id' => 'request-1', 'credit_id' => 'credit-1'], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('authorization: bearer access-token', strtolower(implode("\n", $options['headers'])));
            self::assertStringContainsString('chatgpt-account-id: account-123', strtolower(implode("\n", $options['headers'])));

            return new MockResponse(json_encode(['code' => $code, 'windows_reset' => $code === 'reset' ? 2 : 0], JSON_THROW_ON_ERROR));
        });
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: new RateLimitResetClient($httpClient)));

        self::assertSame($exitCode, $tester->execute([
            '-a' => $this->authFilePath, '-f' => $format, '--request-id' => 'request-1', '--credit-id' => 'credit-1',
        ], ['capture_stderr_separately' => true, 'interactive' => false]));
        self::assertSame(1, $requests);
        self::assertStringContainsString('Reset request ID: request-1', $tester->getErrorOutput());
        if ($format === 'json') {
            self::assertSame([
                'code' => $code, 'windowsReset' => $code === 'reset' ? 2 : 0,
                'requestId' => 'request-1', 'creditId' => 'credit-1',
            ], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            self::assertStringContainsString($message, $tester->getDisplay());
        }
    }

    public static function outcomes(): iterable
    {
        foreach (['text', 'json'] as $format) {
            yield $format . '-reset' => ['reset', 0, 'Usage reset successfully. Windows reset: 2.', $format];
            yield $format . '-already-redeemed' => ['already_redeemed', 0, 'already reset usage successfully', $format];
            yield $format . '-nothing-to-reset' => ['nothing_to_reset', 1, 'does not need a reset', $format];
            yield $format . '-no-credit' => ['no_credit', 1, 'That reset is no longer available', $format];
        }
    }

    public function testResetGeneratesAUuidAndEmitsItBeforeTheRequestWithoutPollutingJson(): void
    {
        $tester = null;
        $requestId = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$tester, &$requestId): MockResponse {
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('credit-1', $body['credit_id']);
            $requestId = $body['redeem_request_id'];
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $requestId);
            self::assertStringContainsString($requestId, $tester->getErrorOutput());

            return new MockResponse('{"code":"reset","windows_reset":2}');
        });
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: new RateLimitResetClient($httpClient)));

        self::assertSame(0, $tester->execute([
            '--auth-file' => $this->authFilePath, '--format' => 'json', '--credit-id' => 'credit-1',
        ], ['capture_stderr_separately' => true]));
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($requestId, $data['requestId']);
        self::assertSame('credit-1', $data['creditId']);
        self::assertStringContainsString('Reset credit ID: credit-1', $tester->getErrorOutput());
    }

    public function testFailedResetCanBeRetriedWithTheOriginalGeneratedRequestId(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            if (count($requests) === 1) {
                throw new TransportException('Request timed out');
            }

            return new MockResponse('{"code":"already_redeemed"}');
        });
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: new RateLimitResetClient($httpClient)));

        self::assertSame(1, $tester->execute(['-a' => $this->authFilePath, '-f' => 'json', '--credit-id' => 'credit-1'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertCount(1, $requests);
        $requestId = $requests[0]['redeem_request_id'];
        self::assertStringContainsString('Request timed out', $tester->getErrorOutput());
        self::assertStringContainsString('--request-id=' . $requestId, $tester->getErrorOutput());

        self::assertSame(0, $tester->execute([
            '-a' => $this->authFilePath, '-f' => 'json', '--credit-id' => 'credit-1', '--request-id' => $requestId,
        ], ['capture_stderr_separately' => true]));
        self::assertCount(2, $requests);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame('already_redeemed', json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    public function testResetReportsNoCreditsWithoutASelection(): void
    {
        $requests = [];
        $client = new RateLimitResetClient(new MockHttpClient(static function (string $method) use (&$requests): MockResponse {
            $requests[] = $method;

            return new MockResponse('{"available_count":0,"credits":[]}');
        }));
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: $client));

        self::assertSame(1, $tester->execute(['--auth-file' => $this->authFilePath, '--request-id' => 'request-1']));
        self::assertStringContainsString('No usage limit resets are available.', $tester->getDisplay());
        self::assertStringContainsString('Available resets: 0', $tester->getDisplay());
        self::assertSame(['GET'], $requests);
        self::assertStringNotContainsString('Retry this attempt', $tester->getDisplay());
    }

    #[DataProvider('interactiveSelections')]
    public function testResetAsksForACreditAndUsesTheSameDetailsAsResets(string $format, array $answers): void
    {
        $payload = $this->creditList();
        $renderer = $this->renderer();
        $listTester = new CommandTester(new ResetsCommand(rateLimitResetClient: $this->clientFor($payload), resetCreditsRenderer: $renderer));
        self::assertSame(0, $listTester->execute(['--auth-file' => $this->authFilePath]));

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($payload, &$requests): MockResponse {
            $requests[] = $method;
            if ($method === 'GET') {
                self::assertSame('https://chatgpt.com/backend-api/wham/rate-limit-reset-credits', $url);

                return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
            }

            self::assertSame('https://chatgpt.com/backend-api/wham/rate-limit-reset-credits/consume', $url);
            self::assertSame([
                'redeem_request_id' => 'request-1', 'credit_id' => 'credit-2',
            ], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));

            return new MockResponse('{"code":"reset","windows_reset":2}');
        });
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: new RateLimitResetClient($httpClient), resetCreditsRenderer: $renderer));
        $tester->setInputs($answers);

        self::assertSame(0, $tester->execute([
            '--auth-file' => $this->authFilePath, '--format' => $format, '--request-id' => 'request-1',
        ], ['capture_stderr_separately' => true, 'interactive' => true]));
        self::assertSame(['GET', 'POST'], $requests);
        $selectionDisplay = $format === 'json' ? $tester->getErrorOutput() : $tester->getDisplay();
        self::assertStringContainsString($listTester->getDisplay(), $selectionDisplay);
        self::assertStringContainsString('Which reset would you like to use?', $selectionDisplay);
        self::assertStringContainsString('[0] credit-1', $selectionDisplay);
        self::assertStringContainsString('[1] credit-2', $selectionDisplay);
        self::assertStringNotContainsString('[2] credit-old', $selectionDisplay);
        self::assertStringNotContainsString('[3] credit-busy', $selectionDisplay);
        self::assertStringContainsString('Reset credit ID: credit-2', $tester->getErrorOutput());
        if ($format === 'json') {
            self::assertSame([
                'code' => 'reset', 'windowsReset' => 2, 'requestId' => 'request-1', 'creditId' => 'credit-2',
            ], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public static function interactiveSelections(): iterable
    {
        yield 'index selection' => ['text', ['1']];
        yield 'ID selection' => ['text', ['credit-2']];
        yield 'invalid selection is retried' => ['text', ['credit-old', '1']];
        yield 'JSON stays clean' => ['json', ['1']];
    }

    public function testResetRequiresACreditIdInNonInteractiveMode(): void
    {
        $client = new RateLimitResetClient(new MockHttpClient(static function (): never {
            self::fail('Missing credit ID in non-interactive mode must not trigger an HTTP request.');
        }));
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: $client));

        self::assertSame(1, $tester->execute(['--auth-file' => $this->authFilePath], ['interactive' => false]));
        self::assertStringContainsString('--credit-id option is required in non-interactive mode', $tester->getDisplay());
    }

    #[DataProvider('cancelledSelections')]
    public function testResetAbortsWithoutConsumingACredit(string $format, array $answers, bool $singleCredit): void
    {
        $requests = [];
        $payload = $this->creditList();
        if ($singleCredit) {
            $payload['available_count'] = 1;
            $payload['credits'] = [$payload['credits'][0]];
        }
        $client = new RateLimitResetClient(new MockHttpClient(static function (string $method) use (&$requests, $payload): MockResponse {
            $requests[] = $method;

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        }));
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: $client));
        $tester->setInputs($answers);

        self::assertSame(0, $tester->execute([
            '--auth-file' => $this->authFilePath, '--format' => $format, '--request-id' => 'request-1',
        ], ['interactive' => true, 'capture_stderr_separately' => true]));
        $display = $format === 'json' ? $tester->getErrorOutput() : $tester->getDisplay();
        self::assertStringContainsString('ENTER to abort', $display);
        self::assertStringContainsString("\nAbort\n", $display);
        self::assertStringNotContainsString('Value "" is invalid', $display);
        self::assertStringNotContainsString('Reset request ID:', $tester->getErrorOutput());
        self::assertSame(['GET'], $requests);
        if ($format === 'json') {
            self::assertSame('', $tester->getDisplay());
        }
    }

    public static function cancelledSelections(): iterable
    {
        yield 'ENTER with multiple credits' => ['text', ['', '1'], false];
        yield 'ENTER with only one credit' => ['text', [''], true];
        yield 'whitespace' => ['text', ['   '], false];
        yield 'ENTER after an invalid choice' => ['text', ['invalid-credit', ''], false];
        yield 'end of input' => ['text', [], true];
        yield 'JSON output' => ['json', [''], false];
    }

    #[DataProvider('unselectableCredits')]
    public function testResetDoesNotFallBackToBackendSelectionWhenNoAvailableIdIsReturned(array $credits): void
    {
        $requests = [];
        $client = new RateLimitResetClient(new MockHttpClient(static function (string $method) use (&$requests, $credits): MockResponse {
            $requests[] = $method;

            return new MockResponse(json_encode(['available_count' => 1, 'credits' => $credits], JSON_THROW_ON_ERROR));
        }));
        $tester = new CommandTester(new ResetCommand(rateLimitResetClient: $client));

        self::assertSame(1, $tester->execute(['--auth-file' => $this->authFilePath], ['interactive' => true]));
        self::assertStringContainsString('No available reset credit IDs were returned', $tester->getDisplay());
        self::assertSame(['GET'], $requests);
    }

    public static function unselectableCredits(): iterable
    {
        yield 'details missing' => [[]];
        foreach (['redeemed', 'redeeming', 'future_status'] as $status) {
            yield $status => [[[
                'id' => 'credit-1', 'reset_type' => 'codex_rate_limits', 'status' => $status,
                'granted_at' => '2026-06-17T00:00:00Z',
            ]]];
        }
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFailBeforeSendingARequest(string $command, array $options, string $message): void
    {
        $client = new RateLimitResetClient(new MockHttpClient(static function (): never {
            self::fail('Invalid options must not trigger an HTTP request.');
        }));
        $tester = new CommandTester($command === 'reset'
            ? new ResetCommand(rateLimitResetClient: $client)
            : new ResetsCommand(rateLimitResetClient: $client));

        self::assertSame(1, $tester->execute($options + ['--auth-file' => $this->authFilePath]));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public static function invalidOptions(): iterable
    {
        yield 'reset format' => ['reset', ['--format' => 'bars'], 'must be either text or json'];
        yield 'resets format' => ['resets', ['--format' => 'bars'], 'must be either text or json'];
        yield 'empty request ID' => ['reset', ['--request-id' => ''], 'request-id option must not be empty'];
        yield 'empty credit ID' => ['reset', ['--credit-id' => ' '], 'credit-id option must not be empty'];
    }

    #[DataProvider('commandNames')]
    public function testCommandsRequireChatgptAuth(string $command): void
    {
        $data = json_decode((string) file_get_contents($this->authFilePath), true, 512, JSON_THROW_ON_ERROR);
        $data['auth_mode'] = 'api_key';
        file_put_contents($this->authFilePath, json_encode($data, JSON_THROW_ON_ERROR));
        $tester = new CommandTester($command === 'reset' ? new ResetCommand() : new ResetsCommand());

        self::assertSame(1, $tester->execute(['--auth-file' => $this->authFilePath]));
        self::assertStringContainsString('ChatGPT authentication is required', $tester->getDisplay());
    }

    #[DataProvider('commandNames')]
    public function testCommandsUseTheDefaultAuthFilePath(string $command): void
    {
        $directory = sys_get_temp_dir() . '/open-ai-device-auth-resets-default-' . uniqid('', true);
        self::assertTrue(mkdir($directory, 0700));
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);

        try {
            self::assertTrue(chdir($directory));
            $tester = new CommandTester($command === 'reset' ? new ResetCommand() : new ResetsCommand());
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('Unable to read auth file at ./auth.json.', $tester->getDisplay());
        } finally {
            chdir($workingDirectory);
            rmdir($directory);
        }
    }

    public static function commandNames(): iterable
    {
        yield 'reset' => ['reset'];
        yield 'resets' => ['resets'];
    }

    private function clientFor(array $payload): RateLimitResetClient
    {
        return new RateLimitResetClient(new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR))));
    }

    private function renderer(): ResetCreditsRenderer
    {
        return new ResetCreditsRenderer(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-05T12:00:00Z'));
    }

    private function creditList(): array
    {
        $credits = [];
        foreach (['credit-1' => 'available', 'credit-2' => 'available', 'credit-old' => 'redeemed', 'credit-busy' => 'redeeming'] as $id => $status) {
            $credits[] = [
                'id' => $id,
                'reset_type' => 'codex_rate_limits',
                'status' => $status,
                'granted_at' => '2026-06-17T00:00:00Z',
                'expires_at' => '2026-07-17T00:00:00Z',
                'title' => 'Full reset',
                'description' => 'Weekly + 5 hr',
            ];
        }

        return ['available_count' => 2, 'credits' => $credits];
    }
}
