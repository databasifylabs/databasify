<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Models\DnsZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDatabaseDomainRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dns_zone_id' => ['nullable', 'uuid', 'exists:dns_zones,id'],
        ];
    }

    public function zone(): ?DnsZone
    {
        return $this->filled('dns_zone_id') ? DnsZone::query()->findOrFail($this->string('dns_zone_id')->value()) : null;
    }
}
