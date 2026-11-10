<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\NewDatabase;
use App\DTOs\Databases\VersionLine;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Http\Requests\Concerns\DatabaseValidationRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Models\Database;
use App\Models\Server;
use App\Rules\AvailableServer;
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
            'version_line' => ['nullable', 'string', Rule::in(array_map(fn (VersionLine $line): string => $line->value, $engine->lines ?? []))],
            'primary_server_id' => ['required', 'uuid', new AvailableServer, $this->eachServerOnce(...)],
            'standby_server_ids' => ['array', 'max:'.($engine === null ? 0 : Engine::MAX_STANDBYS)],
            'standby_server_ids.*' => array_merge($this->serverIdRules(), [new SharesPrivateNetwork(Server::query()->find($this->string('primary_server_id')->value()))]),
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

    private function chosenEngine(): ?Engine
    {
        return EngineCatalog::all()->get($this->string('engine')->value());
    }

    private function eachServerOnce(string $attribute, mixed $value, Closure $fail): void
    {
        $picked = collect($this->stringList('standby_server_ids'))->prepend($this->string('primary_server_id')->value());

        if ($picked->duplicates()->isNotEmpty()) {
            $fail('Pick each server only once.');
        }
    }
}
