<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\Engines\EngineDriver;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\ServiceConnection;
use App\DTOs\Databases\VersionLine;
use App\DTOs\Migrations\SourceForm;
use App\Engines\Data\ServiceRecord;

final readonly class Engine
{
    public const int MAX_STANDBYS = 2;

    public const int MAX_READ_ONLY_NODES = 47;

    /**
     * @param  list<VersionLine>  $lines
     * @param  array<string, string>  $environmentExtra
     * @param  class-string<EngineDriver>|null  $driver
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $icon,
        public string $category,
        public string $imageRepository,
        public array $lines,
        public string $defaultLine,
        public string $failoverHint,
        public ?ServiceRecord $serviceRecord,
        public bool $publicCertificate,
        public bool $persistenceChoice,
        public ?SourceForm $migrationSource,
        public ConnectionProfile $connection,
        public ?ConnectionProfile $publicConnection,
        public ?ServiceConnection $serviceConnection,
        public ?string $laravelConnection,
        public array $environmentExtra,
        public bool $readOnlyNodesVote,
        public ?string $plugin,
        public ?string $author,
        public ?string $driver = null,
    ) {}

    public function isCommunity(): bool
    {
        return $this->plugin !== null;
    }

    /**
     * @return array{value: string, label: string, icon: ?string, category: string, max_standbys: int, lines: list<array{value: string, label: string, default: bool}>, community: bool, author: ?string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->id,
            'label' => $this->label,
            'icon' => $this->icon,
            'category' => $this->category,
            'max_standbys' => self::MAX_STANDBYS,
            'lines' => array_map(fn (VersionLine $line): array => $line->toArray(), $this->lines),
            'community' => $this->isCommunity(),
            'author' => $this->author,
        ];
    }
}
