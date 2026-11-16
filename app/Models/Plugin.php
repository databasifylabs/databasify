<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsPluginAutoload;
use App\DTOs\Plugins\PluginAutoload;
use App\DTOs\Plugins\PluginSource;
use App\Models\Concerns\OrdersByName;
use App\Support\Plugins\VersionConstraint;
use Carbon\CarbonImmutable;
use Database\Factories\PluginFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $author
 * @property string|null $author_url
 * @property string $version
 * @property string $source
 * @property string $class
 * @property PluginAutoload $autoload
 * @property string|null $engine
 * @property bool $enabled
 * @property string|null $requires
 * @property string|null $icon
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'name', 'author', 'author_url', 'version', 'source', 'class', 'autoload', 'engine', 'requires', 'icon', 'enabled', 'error'])]
#[UseFactory(PluginFactory::class)]
class Plugin extends Model
{
    /** @use HasFactory<PluginFactory> */
    use HasFactory, HasUuids, OrdersByName;

    public const string KEY_PATTERN = '[a-z0-9][a-z0-9-]{0,39}/[a-z0-9][a-z0-9-]{0,39}';

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public static function root(): string
    {
        return config()->string('databasify.plugins.path');
    }

    public static function incompatibilityWith(?string $requires): ?string
    {
        $version = config()->string('app.version');

        return $requires === null || VersionConstraint::allows($requires, $version) ? null : "Needs Databasify {$requires}; this is {$version}.";
    }

    public function directory(): string
    {
        return self::root()."/{$this->key}";
    }

    public function iconUrl(): ?string
    {
        return $this->icon === null ? null : asset("plugin-assets/{$this->id}/{$this->icon}");
    }

    public function assetPath(string $path): ?string
    {
        $root = realpath("{$this->directory()}/public");
        $file = realpath("{$root}/{$path}");

        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        return $file;
    }

    public function wasUploaded(): bool
    {
        return str_ends_with($this->source, ' (uploaded)');
    }

    public function isCommunity(): bool
    {
        return $this->engine !== null && ! (new PluginSource($this->source))->isOfficial();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->update(['enabled' => $enabled, 'error' => null]);
    }

    public function recordError(?string $error): void
    {
        if ($error !== $this->error) {
            $this->update(['error' => $error]);
        }
    }

    protected function casts(): array
    {
        return [
            'autoload' => AsPluginAutoload::class,
            'enabled' => 'boolean',
        ];
    }
}
