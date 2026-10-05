<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCode;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCredit;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCreditsResponse;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RateLimitResetClient
{
    private const string CREDITS_URL = 'https://chatgpt.com/backend-api/wham/rate-limit-reset-credits';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function list(string $accessToken, string $accountId): RateLimitResetCreditsResponse
    {
        $data = $this->request('GET', self::CREDITS_URL, $accessToken, $accountId);
        $availableCount = $data['available_count'] ?? null;
        $credits = $data['credits'] ?? null;
        if (!is_int($availableCount) || $availableCount < 0 || !is_array($credits) || !array_is_list($credits)) {
            throw new OpenAiDeviceAuthException('Reset credit response contains invalid available_count or credits.');
        }

        $rows = [];
        foreach ($credits as $credit) {
            if (!is_array($credit)) {
                throw new OpenAiDeviceAuthException('Reset credit response contains an invalid credit.');
            }

            $rows[] = new RateLimitResetCredit(
                $this->requiredString($credit, 'id'),
                $this->requiredString($credit, 'reset_type'),
                $this->requiredString($credit, 'status'),
                $this->requiredString($credit, 'granted_at'),
                $this->optionalString($credit, 'expires_at'),
                $this->optionalString($credit, 'title'),
                $this->optionalString($credit, 'description')
            );
        }

        return new RateLimitResetCreditsResponse($availableCount, $rows);
    }

    public function consume(
        string $accessToken,
        string $accountId,
        string $redeemRequestId,
        ?string $creditId = null
    ): RateLimitResetResponse {
        if (trim($redeemRequestId) === '') {
            throw new OpenAiDeviceAuthException('The reset request ID must not be empty.');
        }
        if ($creditId !== null && trim($creditId) === '') {
            throw new OpenAiDeviceAuthException('The reset credit ID must not be empty.');
        }

        $payload = ['redeem_request_id' => $redeemRequestId];
        if ($creditId !== null) {
            $payload['credit_id'] = $creditId;
        }
        $data = $this->request('POST', self::CREDITS_URL . '/consume', $accessToken, $accountId, $payload);
        $code = is_string($data['code'] ?? null) ? RateLimitResetCode::tryFrom($data['code']) : null;
        $windowsReset = array_key_exists('windows_reset', $data) ? $data['windows_reset'] : 0;
        if ($code === null || !is_int($windowsReset) || $windowsReset < 0) {
            throw new OpenAiDeviceAuthException('Reset response contains an invalid code or windows_reset.');
        }

        return new RateLimitResetResponse($code, $windowsReset);
    }

    /**
     * @param array<string, string>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, string $accessToken, string $accountId, ?array $payload = null): array
    {
        if (trim($accessToken) === '' || trim($accountId) === '') {
            throw new OpenAiDeviceAuthException('ChatGPT access token and account ID are required for reset credits.');
        }

        $options = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $accessToken),
                'ChatGPT-Account-Id' => $accountId,
                'Accept' => 'application/json',
            ],
            'max_duration' => $method === 'POST' ? 10 : 5,
        ];
        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new OpenAiDeviceAuthException(sprintf(
                    'Reset credit request failed: HTTP %d - %s',
                    $response->getStatusCode(),
                    $response->getContent(false)
                ));
            }

            return $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new OpenAiDeviceAuthException('Reset credit request failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new OpenAiDeviceAuthException(sprintf('Reset credit response contains an invalid %s.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new OpenAiDeviceAuthException(sprintf('Reset credit response contains an invalid %s.', $key));
        }

        return $value;
    }
}
