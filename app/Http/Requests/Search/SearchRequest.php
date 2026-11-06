<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:100'],
        ];
    }

    public function searchTerm(): string
    {
        return $this->string('q')->trim()->value();
    }
}
