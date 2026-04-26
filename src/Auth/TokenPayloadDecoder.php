<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Auth;

use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;

final class TokenPayloadDecoder
{
    public function extractAccountId(TokenResponse $tokens): string
    {
        $claims = $this->decodeJwtPayload($tokens->idToken);
        $authClaims = $claims['https://api.openai.com/auth'] ?? null;

        if (!is_array($authClaims)) {
            throw new OpenAiDeviceAuthException('Unable to extract account_id from id_token.');
        }

        $accountId = $authClaims['chatgpt_account_id'] ?? null;
        if (!is_string($accountId) || $accountId === '') {
            throw new OpenAiDeviceAuthException('id_token does not contain chatgpt_account_id.');
        }

        return $accountId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new OpenAiDeviceAuthException('Invalid JWT payload received from OpenAI.');
        }

        $decoded = base64_decode($this->base64UrlDecode($parts[1]), true);
        if ($decoded === false) {
            throw new OpenAiDeviceAuthException('Unable to decode token payload.');
        }

        $claims = json_decode($decoded, true);
        if (!is_array($claims)) {
            throw new OpenAiDeviceAuthException('Token payload is not valid JSON.');
        }

        return $claims;
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;

        return strtr($value . str_repeat('=', $padding), '-_', '+/');
    }
}
