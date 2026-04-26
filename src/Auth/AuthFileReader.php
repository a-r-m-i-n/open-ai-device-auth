<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Auth;

use Armin\OpenAiDeviceAuth\Model\AuthFile;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;

final class AuthFileReader
{
    public function read(string $path): AuthFile
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new OpenAiDeviceAuthException(sprintf('Unable to read auth file at %s.', $path));
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new OpenAiDeviceAuthException(sprintf('Unable to read auth file at %s.', $path));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OpenAiDeviceAuthException('auth.json is not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new OpenAiDeviceAuthException('auth.json must contain a JSON object.');
        }

        $authMode = $decoded['auth_mode'] ?? null;
        $lastRefresh = $decoded['last_refresh'] ?? null;
        $tokens = $decoded['tokens'] ?? null;

        if (!is_string($authMode) || $authMode === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing auth_mode.');
        }

        if (!is_array($tokens)) {
            throw new OpenAiDeviceAuthException('auth.json is missing tokens.');
        }

        $idToken = $tokens['id_token'] ?? null;
        $accessToken = $tokens['access_token'] ?? null;
        $refreshToken = $tokens['refresh_token'] ?? null;
        $accountId = $tokens['account_id'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing tokens.id_token.');
        }

        if (!is_string($accessToken) || $accessToken === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing tokens.access_token.');
        }

        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing tokens.refresh_token.');
        }

        if (!is_string($accountId) || $accountId === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing tokens.account_id.');
        }

        if (!is_string($lastRefresh) || $lastRefresh === '') {
            throw new OpenAiDeviceAuthException('auth.json is missing last_refresh.');
        }

        return new AuthFile($authMode, $idToken, $accessToken, $refreshToken, $accountId, $lastRefresh);
    }
}
