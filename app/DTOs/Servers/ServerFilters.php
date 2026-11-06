<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

use App\Enums\Servers\ServerStatus;
use Illuminate\Http\Request;

final readonly class ServerFilters
{
    public function __construct(
        public string $search = '',
        public ?ServerStatus $status = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->string('search')->trim()->value(),
            status: $request->enum('status', ServerStatus::class),
        );
    }

    /**
     * @return array{statuses: list<string>}
     */
    public static function options(): array
    {
        return ['statuses' => array_map(fn (ServerStatus $status): string => $status->value, ServerStatus::cases())];
    }

    /**
     * @return array{search: string, status: string|null}
     */
    public function toArray(): array
    {
        return ['search' => $this->search, 'status' => $this->status?->value];
    }
}
