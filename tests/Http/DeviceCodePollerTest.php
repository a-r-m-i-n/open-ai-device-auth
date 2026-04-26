<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\DeviceCodePoller;
use Armin\OpenAiDeviceAuth\Model\AuthorizationPendingException;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResponse;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeviceCodePollerTest extends TestCase
{
    public function testItReturnsAuthorizationDataOnSuccess(): void
    {
        $poller = new DeviceCodePoller(new MockHttpClient([
            new MockResponse(json_encode([
                'authorization_code' => 'auth-code',
                'code_verifier' => 'verifier',
                'code_challenge' => 'challenge',
            ], JSON_THROW_ON_ERROR)),
        ]));

        $result = $poller->poll(new DeviceCodeResponse('device-1', 'ABCD', 0));

        self::assertSame('auth-code', $result->authorizationCode);
    }

    public function testItSignalsPendingAuthorizationOn403Or404(): void
    {
        $poller403 = new DeviceCodePoller(new MockHttpClient([new MockResponse('', ['http_code' => 403])]));
        $poller404 = new DeviceCodePoller(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));
        $deviceCode = new DeviceCodeResponse('device-1', 'ABCD', 0);

        try {
            $poller403->poll($deviceCode);
            self::fail('403 should raise AuthorizationPendingException.');
        } catch (AuthorizationPendingException) {
        }

        $this->expectException(AuthorizationPendingException::class);
        $poller404->poll($deviceCode);
    }

    public function testItFailsOnUnexpectedHttpStatus(): void
    {
        $poller = new DeviceCodePoller(new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $poller->poll(new DeviceCodeResponse('device-1', 'ABCD', 0));
    }
}
