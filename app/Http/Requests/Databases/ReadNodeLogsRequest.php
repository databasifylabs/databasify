<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadNodeLogsRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', Rule::in($this->routeDatabase()->processNames())],
            'lines' => ['nullable', 'integer', 'min:10', 'max:2000'],
        ];
    }

    public function service(): string
    {
        return $this->string('service')->value();
    }

    public function lines(): int
    {
        return $this->integer('lines', 200);
    }
}
