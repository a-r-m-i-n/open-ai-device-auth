<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Http;

use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\UsageResponse;
use Armin\OpenAiDeviceAuth\Model\UsageWindow;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class UsageClient
{
    private const USAGE_URL = 'https://chatgpt.com/backend-api/wham/usage';

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function fetch(string $accessToken): UsageResponse
    {
        $response = $this->httpClient->request('GET', self::USAGE_URL, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $accessToken),
                'Accept' => 'application/json',
            ],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new OpenAiDeviceAuthException($this->formatHttpError('Usage request failed', $response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        $rateLimits = $this->extractRateLimitsPayload($data);

        $primary = $this->normalizeWindow(
            $rateLimits['primary'] ?? $rateLimits['primary_window'] ?? null,
            'primary'
        );
        $secondary = null;
        $secondaryPayload = $rateLimits['secondary'] ?? $rateLimits['secondary_window'] ?? null;
        if ($secondaryPayload !== null) {
            $secondary = $this->normalizeWindow($secondaryPayload, 'secondary');
        }

        $rateLimitReachedType = $rateLimits['rateLimitReachedType'] ?? $rateLimits['rate_limit_reached_type'] ?? null;
        if ($rateLimitReachedType !== null && !is_string($rateLimitReachedType)) {
            throw new OpenAiDeviceAuthException('Usage response contains an invalid rateLimitReachedType.');
        }

        return new UsageResponse($primary, $secondary, $rateLimitReachedType);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractRateLimitsPayload(array $data): array
    {
        $rateLimits = $data['rateLimits'] ?? $data['rate_limits'] ?? $data['rate_limit'] ?? null;
        if (is_array($rateLimits)) {
            return $rateLimits;
        }

        return $data;
    }

    /**
     * @param mixed $window
     */
    private function normalizeWindow(mixed $window, string $name): UsageWindow
    {
        if (!is_array($window)) {
            throw new OpenAiDeviceAuthException(sprintf('Usage response is missing %s window data.', $name));
        }

        $usedPercent = $window['usedPercent'] ?? $window['used_percent'] ?? null;
        $windowDurationMins = $window['windowDurationMins'] ?? $window['window_duration_mins'] ?? null;
        $windowDurationSeconds = $window['limit_window_seconds'] ?? null;
        $resetsAt = $window['resetsAt'] ?? $window['resetAt'] ?? $window['reset_at'] ?? null;

        if (!is_numeric($windowDurationMins) && is_numeric($windowDurationSeconds)) {
            $windowDurationMins = (int) ceil(((int) $windowDurationSeconds) / 60);
        }

        if (!is_numeric($usedPercent) || !is_numeric($windowDurationMins) || !$this->isValidResetValue($resetsAt)) {
            throw new OpenAiDeviceAuthException(sprintf('Usage response contains incomplete %s window data.', $name));
        }

        return new UsageWindow((float) $usedPercent, (int) $windowDurationMins, $this->normalizeResetValue($resetsAt));
    }

    private function isValidResetValue(mixed $resetsAt): bool
    {
        return (is_string($resetsAt) && $resetsAt !== '') || is_numeric($resetsAt);
    }

    private function normalizeResetValue(mixed $resetsAt): string
    {
        if (is_numeric($resetsAt)) {
            return gmdate('c', (int) $resetsAt);
        }

        return $resetsAt;
    }

    private function formatHttpError(string $prefix, ResponseInterface $response): string
    {
        return sprintf('%s: HTTP %d - %s', $prefix, $response->getStatusCode(), $response->getContent(false));
    }
}
