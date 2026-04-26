<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\RefreshTokenClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshTokenClientTest extends TestCase
{
    public function testItSendsTheExpectedRefreshRequestAndMapsTokens(): void
    {
        $capturedOptions = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = [$method, $url, $options];

            return new MockResponse(json_encode([
                'id_token' => 'new-id-token',
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
            ], JSON_THROW_ON_ERROR));
        });

        $response = (new RefreshTokenClient($client))->refresh('refresh-token');

        self::assertSame('POST', $capturedOptions[0]);
        self::assertSame('https://auth.openai.com/oauth/token', $capturedOptions[1]);
        self::assertStringContainsString('"grant_type":"refresh_token"', (string) ($capturedOptions[2]['body'] ?? ''));
        self::assertStringContainsString('"refresh_token":"refresh-token"', (string) ($capturedOptions[2]['body'] ?? ''));
        self::assertSame('new-access-token', $response->accessToken);
        self::assertSame(0, $response->expiresIn);
    }

    public function testItFailsOnUnauthorizedResponse(): void
    {
        $client = new RefreshTokenClient(new MockHttpClient([
            new MockResponse('denied', ['http_code' => 401]),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->refresh('refresh-token');
    }

    public function testItFailsOnUnexpectedPayload(): void
    {
        $client = new RefreshTokenClient(new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'only-access'], JSON_THROW_ON_ERROR)),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->refresh('refresh-token');
    }
}
