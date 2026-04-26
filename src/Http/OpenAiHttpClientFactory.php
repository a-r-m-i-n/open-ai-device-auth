<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiHttpClientFactory
{
    public static function create(): HttpClientInterface
    {
        return HttpClient::create([
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'open-ai-device-auth/1.0.0',
            ],
        ]);
    }
}
