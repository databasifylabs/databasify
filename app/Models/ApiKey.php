<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Passport\Token;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $client_id
 * @property string|null $name
 * @property list<string> $scopes
 * @property bool $revoked
 * @property bool $all_projects
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ApiKey extends Token
{
    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'api_key_project', 'token_id', 'project_id');
    }

    /**
     * @return list<string>|null
     */
    public function allowedProjectIds(): ?array
    {
        if ($this->all_projects) {
            return null;
        }

        return array_values(array_filter($this->projects()->pluck('projects.id')->all(), is_string(...)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'all_projects' => 'bool',
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
