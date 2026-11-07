<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProjectNameRules;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class UpdateProjectRequest extends FormRequest
{
    use ProjectNameRules, ResolvesRouteModel;

    /**
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        return ['name' => $this->projectNameRules($this->routeModel('project', Project::class))];
    }
}
