<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class DeviceCodeResponse
{
    public function __construct(
        public string $deviceAuthId,
        public string $userCode,
        public int $interval
    ) {
    }
}
