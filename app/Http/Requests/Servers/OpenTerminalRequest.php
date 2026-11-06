<?php

declare(strict_types=1);

namespace App\Http\Requests\Servers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenTerminalRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'columns' => ['required', 'integer', 'between:20,500'],
            'rows' => ['required', 'integer', 'between:5,200'],
        ];
    }

    public function columns(): int
    {
        return $this->integer('columns');
    }

    public function rows(): int
    {
        return $this->integer('rows');
    }
}
