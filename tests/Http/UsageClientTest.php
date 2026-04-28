<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\UsageClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UsageClientTest extends TestCase
{
    public function testItFetchesUsageAndNormalizesThePayload(): void
    {
        $capturedOptions = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = [$method, $url, $options];

            return new MockResponse(json_encode([
                'email' => 'example@domain.com',
                'account_id' => 'account-123',
                'user_id' => 'user-456',
                'plan_type' => 'plus',
                'primary' => [
                    'usedPercent' => 12.5,
                    'windowDurationMins' => 180,
                    'resetsAt' => '2026-04-26T12:00:00Z',
                ],
                'secondary' => [
                    'usedPercent' => 80,
                    'windowDurationMins' => 10080,
                    'resetsAt' => '2026-04-30T12:00:00Z',
                ],
                'rateLimitReachedType' => 'soft',
            ], JSON_THROW_ON_ERROR));
        });

        $response = (new UsageClient($client))->fetch('access-token', $this->createIo());

        self::assertSame('GET', $capturedOptions[0]);
        self::assertSame('https://chatgpt.com/backend-api/wham/usage', $capturedOptions[1]);
        $serializedOptions = json_encode($capturedOptions[2], JSON_THROW_ON_ERROR);
        self::assertIsString($serializedOptions);
        self::assertStringContainsStringIgnoringCase('authorization', $serializedOptions);
        self::assertStringContainsString('Bearer access-token', $serializedOptions);
        self::assertSame(12.5, $response->primary->usedPercent);
        self::assertSame(10080, $response->secondary?->windowDurationMins);
        self::assertSame('soft', $response->rateLimitReachedType);
        self::assertSame('example@domain.com', $response->email);
        self::assertSame('account-123', $response->accountId);
        self::assertSame('user-456', $response->userId);
        self::assertSame('plus', $response->planType);
    }

    public function testItSupportsNestedRateLimitsAndSnakeCaseFields(): void
    {
        $client = new UsageClient(new MockHttpClient([
            new MockResponse(json_encode([
                'rate_limit' => [
                    'email' => 'snake@example.com',
                    'account_id' => 'account-snake',
                    'user_id' => 'user-snake',
                    'plan_type' => 'pro',
                    'primary_window' => [
                        'used_percent' => 33,
                        'limit_window_seconds' => 18000,
                        'reset_at' => 1777214400,
                    ],
                    'secondary_window' => [
                        'used_percent' => 70,
                        'window_duration_mins' => 10080,
                        'reset_at' => '2026-04-30T12:00:00Z',
                    ],
                    'rate_limit_reached_type' => 'hard',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $response = $client->fetch('access-token', $this->createIo());

        self::assertSame(33.0, $response->primary->usedPercent);
        self::assertSame(300, $response->primary->windowDurationMins);
        self::assertSame('2026-04-26T14:40:00+00:00', $response->primary->resetsAt);
        self::assertSame(70.0, $response->secondary?->usedPercent);
        self::assertSame('hard', $response->rateLimitReachedType);
        self::assertSame('snake@example.com', $response->email);
        self::assertSame('account-snake', $response->accountId);
        self::assertSame('user-snake', $response->userId);
        self::assertSame('pro', $response->planType);
    }

    public function testItFailsOnUnauthorizedResponse(): void
    {
        $client = new UsageClient(new MockHttpClient([
            new MockResponse('denied', ['http_code' => 403]),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->fetch('access-token', $this->createIo());
    }

    public function testItFailsOnUnexpectedPayload(): void
    {
        $client = new UsageClient(new MockHttpClient([
            new MockResponse(json_encode(['primary' => ['usedPercent' => 25]], JSON_THROW_ON_ERROR)),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->fetch('access-token', $this->createIo());
    }

    private function createIo(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }
}
