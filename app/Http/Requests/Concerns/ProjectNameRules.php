<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProjectNameRules
{
    /**
     * @return array<int, Unique|string>
     */
    protected function projectNameRules(?Project $except = null): array
    {
        return ['required', 'string', 'max:60', Rule::unique('projects', 'name')->ignore($except?->id)];
    }

    public function projectName(): string
    {
        return $this->string('name')->trim()->value();
    }
}
