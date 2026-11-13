<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Enums\Engines\Feature;

final readonly class EngineFacts
{
    /**
     * @param  list<Feature>  $features
     * @param  list<string>  $passwordEncryptions
     * @param  list<string>  $reservedUsers
     * @param  list<string>  $reservedDatabases
     */
    public function __construct(
        public array $features,
        public array $passwordEncryptions,
        public ?string $defaultPasswordEncryption,
        public array $reservedUsers,
        public array $reservedDatabases,
        public Ports $ports,
        public HostProfile $hostProfile,
    ) {}

    public function offers(Feature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
