<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class RateLimitResetCreditsResponse
{
    /** @param list<RateLimitResetCredit> $credits */
    public function __construct(
        public int $availableCount,
        public array $credits
    ) {
    }

    /** @return array{availableCount: int, credits: list<array<string, string|null>>} */
    public function toArray(): array
    {
        return [
            'availableCount' => $this->availableCount,
            'credits' => array_map(static fn (RateLimitResetCredit $credit): array => $credit->toArray(), $this->credits),
        ];
    }
}
