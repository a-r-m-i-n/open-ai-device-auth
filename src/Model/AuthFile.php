<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class AuthFile
{
    public function __construct(
        public string $authMode,
        public string $idToken,
        public string $accessToken,
        public string $refreshToken,
        public string $accountId,
        public string $lastRefresh
    ) {
    }
}
