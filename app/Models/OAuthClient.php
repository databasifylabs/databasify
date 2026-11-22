<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OAuthClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Passport\Client;

/**
 * @property string $name
 * @property list<string> $redirect_uris
 * @property list<non-empty-string>|null $scopes
 * @property bool $all_projects
 * @property CarbonImmutable|null $approved_at
 * @property string|null $tokens_max_last_used_at
 */
class OAuthClient extends Client
{
    /** @use HasFactory<OAuthClientFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'oauth_client_project', 'client_id', 'project_id');
    }

    public static function hostOf(string $redirect): string
    {
        $host = parse_url($redirect, PHP_URL_HOST);

        if (is_string($host)) {
            return $host;
        }

        $scheme = parse_url($redirect, PHP_URL_SCHEME);

        return is_string($scheme) ? $scheme : '';
    }

    public function redirectHosts(): string
    {
        return collect($this->redirect_uris)->map(self::hostOf(...))->unique()->implode(', ');
    }

    protected static function newFactory(): OAuthClientFactory
    {
        return OAuthClientFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'all_projects' => 'bool',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
