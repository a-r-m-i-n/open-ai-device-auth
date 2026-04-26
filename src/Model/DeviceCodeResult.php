<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class DeviceCodeResult
{
    public function __construct(
        public string $authorizationCode,
        public string $codeVerifier,
        public string $codeChallenge
    ) {
    }
}
