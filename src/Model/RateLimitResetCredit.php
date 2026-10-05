<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Model;

final readonly class RateLimitResetCredit
{
    public function __construct(
        public string $id,
        public string $resetType,
        public string $status,
        public string $grantedAt,
        public ?string $expiresAt = null,
        public ?string $title = null,
        public ?string $description = null
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'resetType' => $this->resetType,
            'status' => $this->status,
            'grantedAt' => $this->grantedAt,
            'expiresAt' => $this->expiresAt,
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}
