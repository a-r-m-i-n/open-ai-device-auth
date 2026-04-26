<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class TokenResponse
{
    public function __construct(
        public string $idToken,
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn
    ) {
    }
}
