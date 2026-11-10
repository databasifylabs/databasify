<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DTOs\Recovery\RecoverySettings;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\Persistence;
use App\Models\Database;

final readonly class NewDatabase
{
    /**
     * @param  list<string>  $standbyServerIds
     * @param  array<string, string>|null  $secrets
     * @param  array<string, string>  $config
     */
    public function __construct(
        public string $name,
        public string $engine,
        public string $primaryServerId,
        public array $standbyServerIds = [],
        public ?string $versionLine = null,
        public ?array $secrets = null,
        public ?RecoverySource $restore = null,
        public array $config = [],
        public Persistence $persistence = Persistence::Snapshots,
        public ?EngineImage $image = null,
        public ?Database $accountsFrom = null,
        public ?RecoverySettings $recovery = null,
    ) {}

    /**
     * @return list<string>
     */
    public function memberServerIds(): array
    {
        return array_merge([$this->primaryServerId], $this->standbyServerIds);
    }

    public function mode(): DatabaseMode
    {
        return DatabaseMode::forMembers(count($this->memberServerIds()));
    }
}
