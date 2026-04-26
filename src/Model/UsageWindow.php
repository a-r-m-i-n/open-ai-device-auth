<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class UsageWindow
{
    public function __construct(
        public float $usedPercent,
        public int $windowDurationMins,
        public string $resetsAt
    ) {
    }

    /**
     * @return array{usedPercent: float, windowDurationMins: int, resetsAt: string}
     */
    public function toArray(): array
    {
        return [
            'usedPercent' => $this->usedPercent,
            'windowDurationMins' => $this->windowDurationMins,
            'resetsAt' => $this->resetsAt,
        ];
    }
}
