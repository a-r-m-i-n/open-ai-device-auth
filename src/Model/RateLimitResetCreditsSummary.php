<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class RateLimitResetCreditsSummary
{
    public function __construct(public int $availableCount)
    {
    }

    /** @return array{availableCount: int} */
    public function toArray(): array
    {
        return ['availableCount' => $this->availableCount];
    }
}
