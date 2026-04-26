<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\TokenExchanger;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResult;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenExchangerTest extends TestCase
{
    public function testItMapsTheTokenResponse(): void
    {
        $exchanger = new TokenExchanger(new MockHttpClient([
            new MockResponse(json_encode([
                'id_token' => 'id-token',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]));

        $response = $exchanger->exchange(new DeviceCodeResult('auth-code', 'verifier', 'challenge'));

        self::assertSame('id-token', $response->idToken);
        self::assertSame('access-token', $response->accessToken);
        self::assertSame('refresh-token', $response->refreshToken);
    }

    public function testItFailsOnUnexpectedHttpStatus(): void
    {
        $exchanger = new TokenExchanger(new MockHttpClient([
            new MockResponse('denied', ['http_code' => 401]),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $exchanger->exchange(new DeviceCodeResult('auth-code', 'verifier', 'challenge'));
    }
}
