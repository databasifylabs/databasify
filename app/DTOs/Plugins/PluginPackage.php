<?php

declare(strict_types=1);

namespace App\DTOs\Plugins;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PluginPackage
{
    public function __construct(
        public string $key,
        public string $name,
        public string $author,
        public ?string $authorUrl,
        public string $version,
        public string $class,
        public PluginAutoload $autoload,
        public ?string $requires,
        public ?string $icon,
        public ?string $engine,
    ) {}

    public static function read(string $directory): self
    {
        $manifest = json_decode((string) @file_get_contents("{$directory}/plugin.json"), true);
        $manifest = is_array($manifest) ? $manifest : [];
        $key = self::stringIn($manifest, 'key');
        $name = self::stringIn($manifest, 'name');
        $version = self::stringIn($manifest, 'version');
        $class = self::stringIn($manifest, 'class');
        $autoload = PluginAutoload::fromArray((array) Arr::get($manifest, 'autoload', []));

        if ($key === null || $name === null || $version === null || $class === null || $autoload->isEmpty()) {
            throw ValidationException::withMessages(['source' => 'This is not a Databasify plugin: its plugin.json needs a key, name, version, class and autoload.']);
        }

        $icon = self::stringIn($manifest, 'icon') ?? 'icon.svg';
        $icon = ! str_contains($icon, '..') && is_file("{$directory}/public/{$icon}") ? $icon : null;

        return new self(
            key: $key,
            name: Str::limit($name, 100),
            author: self::stringIn($manifest, 'author.name') ?? Str::before($key, '/'),
            authorUrl: self::webAddressIn($manifest, 'author.url'),
            version: $version,
            class: $class,
            autoload: $autoload,
            requires: self::stringIn($manifest, 'requires'),
            icon: $icon,
            engine: self::stringIn($manifest, 'engine'),
        );
    }

    /**
     * @param  array<mixed>  $manifest
     */
    private static function stringIn(array $manifest, string $path): ?string
    {
        $value = Arr::get($manifest, $path);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<mixed>  $manifest
     */
    private static function webAddressIn(array $manifest, string $path): ?string
    {
        $url = self::stringIn($manifest, $path);

        return $url !== null && Str::startsWith($url, ['https://', 'http://']) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /**
     * @return array{name: string, author: string, author_url: ?string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engine: ?string}
     */
    public function attributes(): array
    {
        return ['name' => $this->name, 'author' => $this->author, 'author_url' => $this->authorUrl, 'version' => $this->version, 'class' => $this->class, 'autoload' => $this->autoload, 'requires' => $this->requires, 'icon' => $this->icon, 'engine' => $this->engine];
    }

    /**
     * @return array{key: string, source: string, name: string, author: string, author_url: ?string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engine: ?string}
     */
    public function toInstallRow(string $source): array
    {
        return array_merge(['key' => $this->key, 'source' => $source], $this->attributes());
    }

    /**
     * @return array{name: string, author: string, author_url: ?string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engine: ?string, source: string, error: null}
     */
    public function toUpdateRow(string $source): array
    {
        return array_merge($this->attributes(), ['source' => $source, 'error' => null]);
    }
}
