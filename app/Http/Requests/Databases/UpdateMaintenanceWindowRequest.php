<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\MaintenanceWindow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceWindowRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'day' => ['required', 'integer', 'between:0,6'],
            'hour' => ['required', 'integer', 'between:0,23'],
        ];
    }

    public function toWindow(): MaintenanceWindow
    {
        return new MaintenanceWindow($this->integer('day'), $this->integer('hour'));
    }
}
