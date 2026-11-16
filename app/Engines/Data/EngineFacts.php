<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Enums\Databases\PasswordEncryption;
use App\Enums\Engines\Feature;

final readonly class EngineFacts
{
    /**
     * @param  list<Feature>  $features
     * @param  list<string>  $reservedUsers
     * @param  list<string>  $reservedDatabases
     * @param  list<string>  $primaryControlledSettings
     * @param  list<PasswordEncryption>  $passwordEncryptions
     */
    public function __construct(
        public array $features,
        public array $reservedUsers,
        public array $reservedDatabases,
        public Ports $ports,
        public HostProfile $hostProfile,
        public array $primaryControlledSettings = [],
        public bool $seedClustersFirst = false,
        public array $passwordEncryptions = [],
        public ?PasswordEncryption $defaultPasswordEncryption = null,
    ) {}

    public function offers(Feature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
