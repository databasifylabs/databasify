<?php

declare(strict_types=1);

namespace App\Engines;

use App\DTOs\Databases\VersionLine;
use App\DTOs\Migrations\SourceForm;
use App\Engines\Data\ServiceRecord;

final readonly class Engine
{
    public const int MAX_STANDBYS = 2;

    /**
     * @param  list<VersionLine>  $lines
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
    ) {}

    /**
     * @return array{value: string, label: string, icon: ?string, category: string, max_standbys: int, lines: list<array{value: string, label: string, default: bool}>}
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
        ];
    }
}
