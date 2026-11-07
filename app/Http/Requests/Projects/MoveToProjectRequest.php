<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Contracts\Projects\Movable;
use App\Models\Project;
use App\Rules\ExistsInProject;
use App\Support\Projects\ProjectContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveToProjectRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys($this->movableModels()))],
            'id' => ['required', 'uuid', ...$this->resourceRules()],
            'project_id' => ['required', 'uuid', Rule::exists('projects', 'id'), Rule::notIn([ProjectContext::current()->id()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['project_id.not_in' => 'Pick a different project.'];
    }

    public function resource(): Movable
    {
        $model = $this->movableModel() ?? abort(404);
        $resource = $model::query()->findOrFail($this->string('id')->value());

        if ($resource instanceof Movable) {
            return $resource;
        }

        abort(404);
    }

    public function target(): Project
    {
        return Project::query()->findOrFail($this->string('project_id')->value());
    }

    /**
     * @return list<ExistsInProject>
     */
    private function resourceRules(): array
    {
        $model = $this->movableModel();

        return $model === null ? [] : [new ExistsInProject($model)];
    }

    /**
     * @return class-string<Model>|null
     */
    private function movableModel(): ?string
    {
        return $this->movableModels()[$this->string('type')->value()] ?? null;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function movableModels(): array
    {
        return array_filter(Relation::morphMap(), fn (string $model): bool => is_a($model, Movable::class, true));
    }
}
