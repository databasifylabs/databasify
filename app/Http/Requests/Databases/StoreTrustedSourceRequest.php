<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\NewTrustedSource;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Rules\Ipv4Cidr;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTrustedSourceRequest extends FormRequest
{
    use ReadsTypedInput;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cidr' => ['required', 'string', new Ipv4Cidr],
            'label' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function toSource(): NewTrustedSource
    {
        return NewTrustedSource::normalized(
            $this->string('cidr')->trim()->value(),
            $this->stringOrNull('label'),
        );
    }
}
