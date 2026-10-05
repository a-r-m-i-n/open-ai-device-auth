<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\RateLimitResetClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RateLimitResetClientTest extends TestCase
{
    public function testItListsCreditsWithoutInferringTheCountFromRows(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://chatgpt.com/backend-api/wham/rate-limit-reset-credits', $url);
            $this->assertAuthHeaders($options);

            return new MockResponse(json_encode([
                'available_count' => 5,
                'total_earned_count' => 8,
                'credits' => [
                    [
                        'id' => 'credit-1',
                        'reset_type' => 'codex_rate_limits',
                        'status' => 'available',
                        'granted_at' => '2026-06-17T00:00:00Z',
                        'expires_at' => '2026-07-17T00:00:00Z',
                        'title' => 'Full reset',
                        'description' => 'Weekly + 5 hr',
                        'profile_user_id' => '@friend',
                    ],
                    [
                        'id' => 'credit-2',
                        'reset_type' => 'future_reset_type',
                        'status' => 'future_status',
                        'granted_at' => '2026-06-18T00:00:00Z',
                        'expires_at' => null,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $result = (new RateLimitResetClient($httpClient))->list('access-token', 'account-123');
        self::assertSame(5, $result->availableCount);
        self::assertCount(2, $result->credits);
        self::assertSame([
            'id' => 'credit-1',
            'resetType' => 'codex_rate_limits',
            'status' => 'available',
            'grantedAt' => '2026-06-17T00:00:00Z',
            'expiresAt' => '2026-07-17T00:00:00Z',
            'title' => 'Full reset',
            'description' => 'Weekly + 5 hr',
        ], $result->toArray()['credits'][0]);
        self::assertSame('future_reset_type', $result->credits[1]->resetType);
        self::assertSame('future_status', $result->credits[1]->status);
        self::assertNull($result->credits[1]->expiresAt);
        self::assertNull($result->credits[1]->title);
        self::assertNull($result->credits[1]->description);
    }

    public function testItReturnsAnEmptyList(): void
    {
        $client = $this->clientFor(['available_count' => 0, 'credits' => []]);

        self::assertSame(['availableCount' => 0, 'credits' => []], $client->list('access-token', 'account-123')->toArray());
    }

    #[DataProvider('consumeCases')]
    public function testItConsumesWithTheExpectedContract(string $code, ?string $creditId, bool $success): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($code, $creditId): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/wham/rate-limit-reset-credits/consume', $url);
            $this->assertAuthHeaders($options);
            self::assertStringContainsString('content-type: application/json', strtolower(implode("\n", $options['headers'])));
            $expected = ['redeem_request_id' => 'request-123'];
            if ($creditId !== null) {
                $expected['credit_id'] = $creditId;
            }
            self::assertSame($expected, json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));

            return new MockResponse(json_encode(['code' => $code, 'windows_reset' => $code === 'reset' ? 2 : 0], JSON_THROW_ON_ERROR));
        });

        $result = (new RateLimitResetClient($httpClient))->consume('access-token', 'account-123', 'request-123', $creditId);
        self::assertSame(RateLimitResetCode::from($code), $result->code);
        self::assertSame($success, $result->isSuccess());
        self::assertSame(['code' => $code, 'windowsReset' => $code === 'reset' ? 2 : 0], $result->toArray());
    }

    public static function consumeCases(): iterable
    {
        yield 'automatic selection' => ['reset', null, true];
        yield 'selected credit' => ['reset', 'credit-1', true];
        yield 'nothing to reset' => ['nothing_to_reset', null, false];
        yield 'no credit' => ['no_credit', 'credit-1', false];
        yield 'idempotent success' => ['already_redeemed', null, true];
    }

    public function testRetryKeepsTheSameRequestAndCreditIds(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['code' => count($requests) === 1 ? 'reset' : 'already_redeemed'], JSON_THROW_ON_ERROR));
        });
        $client = new RateLimitResetClient($httpClient);

        self::assertTrue($client->consume('access-token', 'account-123', 'same-request', 'credit-1')->isSuccess());
        self::assertTrue($client->consume('access-token', 'account-123', 'same-request', 'credit-1')->isSuccess());
        self::assertSame($requests[0], $requests[1]);
        self::assertSame(['redeem_request_id' => 'same-request', 'credit_id' => 'credit-1'], $requests[0]);
    }

    #[DataProvider('invalidIds')]
    public function testItRejectsEmptyInputsWithoutSendingARequest(string $token, string $accountId, string $requestId, ?string $creditId): void
    {
        $client = new RateLimitResetClient(new MockHttpClient(static function (): never {
            self::fail('An invalid input must not trigger an HTTP request.');
        }));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->consume($token, $accountId, $requestId, $creditId);
    }

    public static function invalidIds(): iterable
    {
        yield 'request ID' => ['access-token', 'account-123', '', null];
        yield 'credit ID' => ['access-token', 'account-123', 'request-1', ' '];
        yield 'token' => ['', 'account-123', 'request-1', null];
        yield 'account ID' => ['access-token', '', 'request-1', null];
    }

    #[DataProvider('invalidLists')]
    public function testItRejectsMalformedCreditLists(array $payload): void
    {
        $this->expectException(OpenAiDeviceAuthException::class);
        $this->clientFor($payload)->list('access-token', 'account-123');
    }

    public static function invalidLists(): iterable
    {
        yield 'missing count' => ['payload' => ['credits' => []]];
        yield 'negative count' => ['payload' => ['available_count' => -1, 'credits' => []]];
        yield 'invalid count' => ['payload' => ['available_count' => '1', 'credits' => []]];
        yield 'missing list' => ['payload' => ['available_count' => 1]];
        yield 'non-list credits' => ['payload' => ['available_count' => 1, 'credits' => ['unexpected' => []]]];
        yield 'missing credit fields' => ['payload' => ['available_count' => 1, 'credits' => [['id' => 'credit-1']]]];
        yield 'invalid optional field' => ['payload' => ['available_count' => 1, 'credits' => [[
            'id' => 'credit-1', 'reset_type' => 'codex_rate_limits', 'status' => 'available',
            'granted_at' => '2026-06-17T00:00:00Z', 'title' => 123,
        ]]]];
    }

    #[DataProvider('invalidConsumeResponses')]
    public function testItRejectsMalformedConsumeResponses(array $payload): void
    {
        $this->expectException(OpenAiDeviceAuthException::class);
        $this->clientFor($payload)->consume('access-token', 'account-123', 'request-1');
    }

    public static function invalidConsumeResponses(): iterable
    {
        yield 'missing code' => ['payload' => ['windows_reset' => 2]];
        yield 'unknown code' => ['payload' => ['code' => 'future_code']];
        yield 'negative window count' => ['payload' => ['code' => 'reset', 'windows_reset' => -1]];
        yield 'null window count' => ['payload' => ['code' => 'reset', 'windows_reset' => null]];
    }

    #[DataProvider('httpErrors')]
    public function testItSurfacesHttpErrors(int $status, bool $consume): void
    {
        $client = new RateLimitResetClient(new MockHttpClient(new MockResponse('denied', ['http_code' => $status])));
        $this->expectException(OpenAiDeviceAuthException::class);
        $this->expectExceptionMessage(sprintf('HTTP %d - denied', $status));
        if ($consume) {
            $client->consume('access-token', 'account-123', 'request-1');
        } else {
            $client->list('access-token', 'account-123');
        }
    }

    public static function httpErrors(): iterable
    {
        yield 'unauthorized list' => [401, false];
        yield 'forbidden consume' => [403, true];
        yield 'undeployed list' => [404, false];
        yield 'server error consume' => [500, true];
    }

    public function testItWrapsInvalidJson(): void
    {
        $client = new RateLimitResetClient(new MockHttpClient(new MockResponse('not-json')));
        $this->expectException(OpenAiDeviceAuthException::class);
        $client->list('access-token', 'account-123');
    }

    public function testItWrapsTransportErrorsWithoutRetrying(): void
    {
        $requests = 0;
        $client = new RateLimitResetClient(new MockHttpClient(static function () use (&$requests): never {
            ++$requests;
            throw new TransportException('Request timed out');
        }));

        try {
            $client->consume('access-token', 'account-123', 'request-1');
            self::fail('A transport error must be reported.');
        } catch (OpenAiDeviceAuthException $exception) {
            self::assertStringContainsString('Request timed out', $exception->getMessage());
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
            self::assertSame(1, $requests);
        }
    }

    private function clientFor(array $payload): RateLimitResetClient
    {
        return new RateLimitResetClient(new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR))));
    }

    private function assertAuthHeaders(array $options): void
    {
        $headers = strtolower(implode("\n", $options['headers']));
        self::assertStringContainsString('authorization: bearer access-token', $headers);
        self::assertStringContainsString('chatgpt-account-id: account-123', $headers);
        self::assertStringContainsString('accept: application/json', $headers);
    }
}
