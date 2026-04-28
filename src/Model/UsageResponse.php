<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class UsageResponse
{
    public function __construct(
        public UsageWindow $primary,
        public ?UsageWindow $secondary,
        public ?string $rateLimitReachedType,
        public ?string $email = null,
        public ?string $accountId = null,
        public ?string $userId = null,
        public ?string $planType = null
    ) {
    }

    /**
     * @return array{
     *   primary: array{usedPercent: float, windowDurationMins: int, resetsAt: string},
     *   secondary?: array{usedPercent: float, windowDurationMins: int, resetsAt: string},
     *   rateLimitReachedType?: string,
     *   email?: string,
     *   accountId?: string,
     *   userId?: string,
     *   planType?: string
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

        if ($this->email !== null) {
            $data['email'] = $this->email;
        }

        if ($this->accountId !== null) {
            $data['accountId'] = $this->accountId;
        }

        if ($this->userId !== null) {
            $data['userId'] = $this->userId;
        }

        if ($this->planType !== null) {
            $data['planType'] = $this->planType;
        }

        return $data;
    }
}
