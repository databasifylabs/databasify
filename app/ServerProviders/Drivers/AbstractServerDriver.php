<?php

declare(strict_types=1);

namespace App\ServerProviders\Drivers;

use App\DTOs\Forms\DynamicForm;
use App\Enums\Servers\OperatingSystem;
use App\Models\ServerProvider;
use App\ServerProviders\Contracts\ServerDriver;
use Illuminate\Support\Arr;

abstract readonly class AbstractServerDriver implements ServerDriver
{
    protected const int SMALLEST_MEMORY_MB = 1024;

    protected const int MB_PER_GB = 1024;

    final public function __construct(protected ServerProvider $serverProvider) {}

    public function label(): string
    {
        return config()->string($this->key('label'), $this->serverProvider->type);
    }

    public function icon(): ?string
    {
        $icon = config()->string($this->key('icon'), '');

        return $icon === '' ? null : asset($icon);
    }

    public function form(): DynamicForm
    {
        return DynamicForm::fromArray(config()->array($this->key('form'), []));
    }

    public function serverLabel(): string
    {
        return config()->string($this->key('server_label'), '');
    }

    public function sshUser(): string
    {
        return config()->string($this->key('ssh_user'), '');
    }

    public function image(OperatingSystem $os): int|string|null
    {
        $image = config()->array($this->key('images'), [])[$os->value] ?? null;

        return is_int($image) || is_string($image) ? $image : null;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config($this->key($key), $default);
    }

    public function toArray(): array
    {
        /** @var array<string, mixed> $extra */
        $extra = Arr::except(config()->array($this->key(), []), ['handler', 'label', 'icon', 'form', 'images']);

        return array_merge([
            'value' => $this->serverProvider->type,
            'label' => $this->label(),
            'icon' => $this->icon(),
            'form' => $this->form()->toArray(),
        ], $extra);
    }

    private function key(string $name = ''): string
    {
        return rtrim("server-provider.providers.{$this->serverProvider->type}.{$name}", '.');
    }
}
