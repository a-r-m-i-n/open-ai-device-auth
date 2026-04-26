<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\AuthorizationPendingException;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResponse;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResult;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DeviceCodePoller
{
    public const TIMEOUT_SECONDS = 900;

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function poll(DeviceCodeResponse $deviceCode): DeviceCodeResult
    {
        sleep(max(1, $deviceCode->interval));

        $response = $this->httpClient->request('POST', DeviceCodeClient::API_BASE_URL . '/deviceauth/token', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'device_auth_id' => $deviceCode->deviceAuthId,
                'user_code' => $deviceCode->userCode,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 403 || $statusCode === 404) {
            throw new AuthorizationPendingException();
        }

        if ($statusCode >= 400) {
            throw new OpenAiDeviceAuthException($this->formatHttpError('Polling failed', $response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        $authorizationCode = $data['authorization_code'] ?? null;
        $codeVerifier = $data['code_verifier'] ?? null;
        $codeChallenge = $data['code_challenge'] ?? null;

        if (!is_string($authorizationCode) || !is_string($codeVerifier) || !is_string($codeChallenge)) {
            throw new OpenAiDeviceAuthException('OpenAI returned an incomplete authorization response.');
        }

        return new DeviceCodeResult($authorizationCode, $codeVerifier, $codeChallenge);
    }

    private function formatHttpError(string $prefix, ResponseInterface $response): string
    {
        return sprintf('%s: HTTP %d - %s', $prefix, $response->getStatusCode(), $response->getContent(false));
    }
}
