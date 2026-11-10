<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\Enums\Migrations\MigrationMode;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MigrationSourceRequest extends FormRequest
{
    use ReadsTypedInput, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'tls' => ['boolean'],
            'mode' => ['sometimes', Rule::enum(MigrationMode::class)],
        ], $this->sourceForm()?->rules() ?? []);
    }

    private function sourceForm(): ?SourceForm
    {
        $database = $this->routeDatabase();
        $form = $database->engine()->migrationSource;

        return $form === null ? null : new SourceForm($form->defaultPort, $form->credentialsRequired, $form->selectsDatabases, $database->engine_capabilities->reservedDatabases ?? []);
    }

    public function source(): MigrationSource
    {
        return new MigrationSource(
            host: $this->string('host')->value(),
            port: $this->integer('port'),
            username: $this->string('username')->value(),
            password: $this->string('password')->value(),
            tls: $this->boolean('tls', true),
            databases: $this->stringList('databases'),
        );
    }

    public function mode(): MigrationMode
    {
        return $this->enum('mode', MigrationMode::class) ?? MigrationMode::Continuous;
    }
}
