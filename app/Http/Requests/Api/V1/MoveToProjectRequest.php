<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Projects\MoveToProjectRequest as WebMoveToProjectRequest;
use App\Support\Projects\ProjectContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class MoveToProjectRequest extends WebMoveToProjectRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $allowedIds = ProjectContext::current()->allowedIds();

        if ($allowedIds === null) {
            return $rules;
        }

        $projectRules = is_array($rules['project_id']) ? $rules['project_id'] : [$rules['project_id']];

        return [...$rules, 'project_id' => [...$projectRules, Rule::in($allowedIds)]];
    }
}
