<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\Api\AccessGrant;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ChoosesApiAccess
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function accessRules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            ...collect(AccessArea::cases())->mapWithKeys(fn (AccessArea $area): array => [
                "permissions.{$area->value}" => ['required', $area->isReadOnly() ? Rule::enum(AccessLevel::class)->except(AccessLevel::Write) : Rule::enum(AccessLevel::class)],
            ])->all(),
            'projects' => ['required', $this->limitsProjects() ? 'array' : Rule::in(['all'])],
            'projects.*' => ['uuid', Rule::exists('projects', 'id')],
        ];
    }

    /**
     * @return callable(Validator): void
     */
    protected function accessAfter(): callable
    {
        return function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->scopes() === []) {
                $validator->errors()->add('permissions', 'Give access to at least one area.');
            }
        };
    }

    public function toAccessGrant(): AccessGrant
    {
        return new AccessGrant(
            $this->scopes(),
            $this->limitsProjects() ? array_values(array_filter($this->array('projects'), is_string(...))) : null,
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function scopes(): array
    {
        return array_values(collect(AccessArea::cases())
            ->map(fn (AccessArea $area): ?string => match ($this->enum("permissions.{$area->value}", AccessLevel::class)) {
                AccessLevel::Write => $area->writeScope(),
                AccessLevel::Read => $area->readScope(),
                default => null,
            })
            ->filter()
            ->all());
    }

    private function limitsProjects(): bool
    {
        return is_array($this->input('projects'));
    }
}
