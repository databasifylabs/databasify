<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class LastFailure
{
    public function __construct(
        public string $logId,
        public string $name,
        public ?string $failedAt,
    ) {}

    /**
     * @return array{log_id: string, name: string, failed_at: ?string}
     */
    public function toArray(): array
    {
        return ['log_id' => $this->logId, 'name' => $this->name, 'failed_at' => $this->failedAt];
    }
}
