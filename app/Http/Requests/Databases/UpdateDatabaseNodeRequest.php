<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Http\Requests\Concerns\DatabaseValidationRules;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\DatabaseNode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDatabaseNodeRequest extends FormRequest
{
    use DatabaseValidationRules, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => array_merge(['required'], $this->nodeNameRules($this->routeModel('node', DatabaseNode::class))),
        ];
    }

    public function nodeName(): string
    {
        return $this->string('name')->value();
    }
}
