<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\NewDatabase;
use App\DTOs\Databases\VersionLine;
use App\Http\Requests\Concerns\DatabaseValidationRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Models\Database;
use App\Models\Server;
use App\Rules\AvailableServer;
use App\Rules\HasClusterMemory;
use App\Rules\SharesPrivateNetwork;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseRequest extends FormRequest
{
    use DatabaseValidationRules, ReadsTypedInput;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $engine = $this->chosenEngine();

        return [
            'name' => $this->databaseNameRules(),
            'engine' => ['required', Rule::in(Database::engines())],
            'version_line' => ['nullable', 'string', Rule::in($engine?->versions()->map(fn (VersionLine $line): string => $line->value)->all() ?? [])],
            'primary_server_id' => ['required', 'uuid', new AvailableServer, $this->eachServerOnce(...), Rule::when($this->stringList('standby_server_ids') !== [], [new HasClusterMemory])],
            'standby_server_ids' => ['array', 'max:'.($engine?->maxStandbys() ?? 0)],
            'standby_server_ids.*' => array_merge($this->serverIdRules(), [new SharesPrivateNetwork(Server::query()->find($this->string('primary_server_id')->value())), new HasClusterMemory]),
        ];
    }

    public function toDatabase(): NewDatabase
    {
        return new NewDatabase(
            name: $this->string('name')->value(),
            engine: $this->string('engine')->value(),
            primaryServerId: $this->string('primary_server_id')->value(),
            standbyServerIds: $this->stringList('standby_server_ids'),
            versionLine: $this->stringOrNull('version_line'),
        );
    }

    private function chosenEngine(): ?DatabaseDriver
    {
        $engine = $this->string('engine')->value();

        if (! in_array($engine, Database::engines(), true)) {
            return null;
        }

        return Database::providerFor($engine);
    }

    private function eachServerOnce(string $attribute, mixed $value, Closure $fail): void
    {
        $picked = collect($this->stringList('standby_server_ids'))->prepend($this->string('primary_server_id')->value());

        if ($picked->duplicates()->isNotEmpty()) {
            $fail('Pick each server only once.');
        }
    }
}
