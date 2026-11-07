<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\DTOs\Projects\NewProject;
use App\Http\Requests\Concerns\ProjectNameRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class StoreProjectRequest extends FormRequest
{
    use ProjectNameRules;

    /**
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        return ['name' => $this->projectNameRules()];
    }

    public function toProject(): NewProject
    {
        return new NewProject($this->projectName());
    }
}
