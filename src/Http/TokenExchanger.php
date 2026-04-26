<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\DeviceCodeResult;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TokenExchanger
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function exchange(DeviceCodeResult $result): TokenResponse
    {
        $response = $this->httpClient->request('POST', DeviceCodeClient::BASE_URL . '/oauth/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'client_id' => DeviceCodeClient::CLIENT_ID,
                'code' => $result->authorizationCode,
                'code_verifier' => $result->codeVerifier,
                'redirect_uri' => DeviceCodeClient::BASE_URL . '/deviceauth/callback',
            ]),
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new OpenAiDeviceAuthException($this->formatHttpError('Token exchange failed', $response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        $idToken = $data['id_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!is_string($idToken) || !is_string($accessToken) || !is_string($refreshToken) || !is_numeric($expiresIn)) {
            throw new OpenAiDeviceAuthException('OpenAI returned an incomplete token response.');
        }

        return new TokenResponse($idToken, $accessToken, $refreshToken, (int) $expiresIn);
    }

    private function formatHttpError(string $prefix, ResponseInterface $response): string
    {
        return sprintf('%s: HTTP %d - %s', $prefix, $response->getStatusCode(), $response->getContent(false));
    }
}
