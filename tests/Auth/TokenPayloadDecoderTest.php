<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Auth;

use Armin\OpenAiDeviceAuth\Auth\TokenPayloadDecoder;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\TokenResponse;
use PHPUnit\Framework\TestCase;

final class TokenPayloadDecoderTest extends TestCase
{
    public function testItExtractsTheAccountIdFromTheIdToken(): void
    {
        $decoder = new TokenPayloadDecoder();

        $accountId = $decoder->extractAccountId(new TokenResponse($this->createJwt([
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'account-123',
            ],
        ]), 'access', 'refresh', 3600));

        self::assertSame('account-123', $accountId);
    }

    public function testItFailsWhenTheClaimIsMissing(): void
    {
        $decoder = new TokenPayloadDecoder();

        $this->expectException(OpenAiDeviceAuthException::class);
        $decoder->extractAccountId(new TokenResponse($this->createJwt([]), 'access', 'refresh', 3600));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJwt(array $payload): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $header . '.' . $body . '.signature';
    }
}
