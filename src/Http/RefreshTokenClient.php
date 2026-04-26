<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RefreshTokenClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function refresh(string $refreshToken): TokenResponse
    {
        $response = $this->httpClient->request('POST', DeviceCodeClient::BASE_URL . '/oauth/token', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'client_id' => DeviceCodeClient::CLIENT_ID,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new OpenAiDeviceAuthException($this->formatHttpError('Token refresh failed', $response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        $idToken = $data['id_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $newRefreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 0;

        if (!is_string($idToken) || !is_string($accessToken) || !is_string($newRefreshToken) || !is_numeric($expiresIn)) {
            throw new OpenAiDeviceAuthException('OpenAI returned an incomplete refresh token response.');
        }

        return new TokenResponse($idToken, $accessToken, $newRefreshToken, (int) $expiresIn);
    }

    private function formatHttpError(string $prefix, ResponseInterface $response): string
    {
        return sprintf('%s: HTTP %d - %s', $prefix, $response->getStatusCode(), $response->getContent(false));
    }
}
