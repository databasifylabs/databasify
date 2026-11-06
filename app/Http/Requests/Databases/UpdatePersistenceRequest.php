<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Enums\Databases\Persistence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersistenceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'persistence' => ['required', Rule::enum(Persistence::class)],
        ];
    }

    public function persistence(): Persistence
    {
        return $this->enum('persistence', Persistence::class) ?? Persistence::Snapshots;
    }
}
