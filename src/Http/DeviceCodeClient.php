<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\DeviceCodeResponse;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DeviceCodeClient
{
    public const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';
    public const BASE_URL = 'https://auth.openai.com';
    public const API_BASE_URL = self::BASE_URL . '/api/accounts';
    public const VERIFICATION_URL = self::BASE_URL . '/codex/device';

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function requestUserCode(): DeviceCodeResponse
    {
        $response = $this->httpClient->request('POST', self::API_BASE_URL . '/deviceauth/usercode', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'client_id' => self::CLIENT_ID,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            throw new OpenAiDeviceAuthException(
                'Device code login is not enabled for this account. Enable it in ChatGPT Codex security settings.'
            );
        }

        if ($statusCode >= 400) {
            throw new OpenAiDeviceAuthException($this->formatHttpError('Failed to request device code', $response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        $userCode = $data['user_code'] ?? $data['usercode'] ?? null;
        $deviceAuthId = $data['device_auth_id'] ?? null;
        $interval = $data['interval'] ?? 5;

        if (!is_string($userCode) || $userCode === '' || !is_string($deviceAuthId) || $deviceAuthId === '') {
            throw new OpenAiDeviceAuthException('OpenAI returned an incomplete device code response.');
        }

        return new DeviceCodeResponse($deviceAuthId, $userCode, is_numeric($interval) ? (int) $interval : 5);
    }

    private function formatHttpError(string $prefix, ResponseInterface $response): string
    {
        return sprintf('%s: HTTP %d - %s', $prefix, $response->getStatusCode(), $response->getContent(false));
    }
}
