<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use Illuminate\Http\Request;

final readonly class DatabaseFilters
{
    public function __construct(
        public string $search = '',
        public ?DatabaseStatus $status = null,
        public ?string $engine = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->string('search')->trim()->value(),
            status: $request->enum('status', DatabaseStatus::class),
            engine: self::engineIn($request),
        );
    }

    /**
     * @return array{statuses: list<string>, engines: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        return [
            'statuses' => array_map(fn (DatabaseStatus $status): string => $status->value, DatabaseStatus::cases()),
            'engines' => array_values(collect(Database::providers())->map(fn (DatabaseDriver $engine, string $id): array => ['value' => $id, 'label' => $engine->label()])->all()),
        ];
    }

    /**
     * @return array{search: string, status: string|null, engine: string|null}
     */
    public function toArray(): array
    {
        return ['search' => $this->search, 'status' => $this->status?->value, 'engine' => $this->engine];
    }

    private static function engineIn(Request $request): ?string
    {
        $engine = $request->string('engine')->value();

        return in_array($engine, Database::engines(), true) ? $engine : null;
    }
}
