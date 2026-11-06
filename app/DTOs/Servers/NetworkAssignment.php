<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

use App\Models\Network;

final readonly class NetworkAssignment
{
    public function __construct(
        public ?Network $network = null,
        public ?string $privateIp = null,
    ) {}

    /**
     * @return array{network_id: ?string, private_ip: ?string}
     */
    public function toRow(): array
    {
        return ['network_id' => $this->network?->id, 'private_ip' => $this->network === null ? null : $this->privateIp];
    }
}
