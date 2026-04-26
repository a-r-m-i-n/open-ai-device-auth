<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Http;

use Armin\OpenAiDeviceAuth\Http\DeviceCodeClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeviceCodeClientTest extends TestCase
{
    public function testItMapsTheDeviceCodeResponse(): void
    {
        $client = new DeviceCodeClient(new MockHttpClient([
            new MockResponse(json_encode([
                'device_auth_id' => 'device-1',
                'user_code' => 'ABCD',
                'interval' => '5',
            ], JSON_THROW_ON_ERROR)),
        ]));

        $response = $client->requestUserCode();

        self::assertSame('device-1', $response->deviceAuthId);
        self::assertSame('ABCD', $response->userCode);
        self::assertSame(5, $response->interval);
    }

    public function testItThrowsAnInformativeErrorWhenDeviceCodeIsDisabled(): void
    {
        $client = new DeviceCodeClient(new MockHttpClient([
            new MockResponse('disabled', ['http_code' => 404]),
        ]));

        $this->expectException(OpenAiDeviceAuthException::class);
        $client->requestUserCode();
    }
}
