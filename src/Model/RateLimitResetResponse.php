<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class RateLimitResetResponse
{
    public function __construct(
        public RateLimitResetCode $code,
        public int $windowsReset = 0
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->code === RateLimitResetCode::Reset || $this->code === RateLimitResetCode::AlreadyRedeemed;
    }

    /** @return array{code: string, windowsReset: int} */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'windowsReset' => $this->windowsReset];
    }
}
