<?php

declare(strict_types=1);

namespace App\DTOs\Plugins;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PluginPackage
{
    /**
     * @param  list<string>  $engines
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $author,
        public string $version,
        public string $class,
        public PluginAutoload $autoload,
        public ?string $requires,
        public ?string $icon,
        public array $engines,
    ) {}

    public static function read(string $directory): self
    {
        $manifest = json_decode((string) @file_get_contents("{$directory}/composer.json"), true);
        $manifest = is_array($manifest) ? $manifest : [];
        $class = Arr::get($manifest, 'extra.databasify.plugin');
        $autoload = PluginAutoload::fromArray((array) Arr::get($manifest, 'autoload.psr-4', []));

        $name = Arr::get($manifest, 'name');

        if (! is_string($name) || ! str_contains($name, '/') || ! is_string($class) || $autoload->isEmpty()) {
            throw ValidationException::withMessages(['source' => 'This is not a Databasify plugin: its composer.json needs a name, autoload.psr-4 and extra.databasify.plugin.']);
        }

        $version = Arr::get($manifest, 'version');
        $requires = Arr::get($manifest, 'extra.databasify.requires');
        $icon = Arr::get($manifest, 'extra.databasify.icon', 'icon.svg');
        $icon = is_string($icon) && ! str_contains($icon, '..') && is_file("{$directory}/public/{$icon}") ? $icon : null;

        return new self(Str::after($name, '/'), $name, self::authorOf($manifest, $name), is_string($version) ? $version : 'dev', $class, $autoload, is_string($requires) ? $requires : null, $icon, self::enginesOf($manifest));
    }

    /**
     * @param  array<mixed>  $manifest
     */
    private static function authorOf(array $manifest, string $name): string
    {
        $author = Arr::get($manifest, 'authors.0.name');

        return is_string($author) && $author !== '' ? $author : Str::before($name, '/');
    }

    /**
     * @param  array<mixed>  $manifest
     * @return list<string>
     */
    private static function enginesOf(array $manifest): array
    {
        $engines = Arr::get($manifest, 'extra.databasify.engines');

        return is_array($engines) ? array_values(array_unique(array_filter($engines, is_string(...)))) : [];
    }

    /**
     * @return array{name: string, author: string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engines: list<string>}
     */
    public function attributes(): array
    {
        return ['name' => $this->name, 'author' => $this->author, 'version' => $this->version, 'class' => $this->class, 'autoload' => $this->autoload, 'requires' => $this->requires, 'icon' => $this->icon, 'engines' => $this->engines];
    }

    /**
     * @return array{id: string, source: string, name: string, author: string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engines: list<string>}
     */
    public function toInstallRow(string $source): array
    {
        return array_merge(['id' => $this->id, 'source' => $source], $this->attributes());
    }

    /**
     * @return array{name: string, author: string, version: string, class: string, autoload: PluginAutoload, requires: ?string, icon: ?string, engines: list<string>, source: string, error: null}
     */
    public function toUpdateRow(string $source): array
    {
        return array_merge($this->attributes(), ['source' => $source, 'error' => null]);
    }
}
