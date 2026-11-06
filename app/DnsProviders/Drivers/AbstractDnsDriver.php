<?php

declare(strict_types=1);

namespace App\DnsProviders\Drivers;

use App\DnsProviders\Contracts\DnsDriver;
use App\DTOs\Forms\DynamicForm;
use App\Models\DnsProvider;
use Illuminate\Support\Arr;

abstract readonly class AbstractDnsDriver implements DnsDriver
{
    final public function __construct(protected DnsProvider $dnsProvider) {}

    public function label(): string
    {
        return config()->string($this->key('label'), $this->dnsProvider->type);
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

    public function config(string $key, mixed $default = null): mixed
    {
        return config($this->key($key), $default);
    }

    public function toArray(): array
    {
        /** @var array<string, mixed> $extra */
        $extra = Arr::except(config()->array($this->key(), []), ['handler', 'label', 'icon', 'form']);

        return array_merge([
            'value' => $this->dnsProvider->type,
            'label' => $this->label(),
            'icon' => $this->icon(),
            'form' => $this->form()->toArray(),
        ], $extra);
    }

    private function key(string $name = ''): string
    {
        return rtrim("dns-provider.providers.{$this->dnsProvider->type}.{$name}", '.');
    }
}
