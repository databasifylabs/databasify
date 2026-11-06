<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\NewConnectionPool;
use App\Enums\Databases\PoolMode;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionPoolRequest extends FormRequest
{
    use ReadsTypedInput, ResolvesRouteModel;

    public const int MAX_SIZE = 500;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $databaseId = $this->routeDatabase()->id;

        return [
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z][a-z0-9_]*$/', 'not_in:postgres,pgbouncer', Rule::unique('connection_pools', 'name')->where('database_id', $databaseId)],
            'schema' => ['required', 'string', Rule::exists('schemas', 'name')->where('database_id', $databaseId)],
            'mode' => ['required', Rule::enum(PoolMode::class)],
            'size' => ['required', 'integer', 'between:1,'.self::MAX_SIZE],
        ];
    }

    public function toPool(): NewConnectionPool
    {
        return new NewConnectionPool(
            $this->string('name')->value(),
            $this->string('schema')->value(),
            $this->enum('mode', PoolMode::class) ?? PoolMode::Transaction,
            $this->integer('size'),
        );
    }
}
