<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class UsageResponse
{
    public function __construct(
        public UsageWindow $primary,
        public ?UsageWindow $secondary,
        public ?string $rateLimitReachedType
    ) {
    }

    /**
     * @return array{
     *   primary: array{usedPercent: float, windowDurationMins: int, resetsAt: string},
     *   secondary?: array{usedPercent: float, windowDurationMins: int, resetsAt: string},
     *   rateLimitReachedType?: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'primary' => $this->primary->toArray(),
        ];

        if ($this->secondary instanceof UsageWindow) {
            $data['secondary'] = $this->secondary->toArray();
        }

        if ($this->rateLimitReachedType !== null) {
            $data['rateLimitReachedType'] = $this->rateLimitReachedType;
        }

        return $data;
    }
}
