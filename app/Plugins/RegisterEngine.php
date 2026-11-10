<?php

declare(strict_types=1);

namespace App\Plugins;

final class RegisterEngine extends AbstractProviderRegistrar
{
    public function category(string $category): static
    {
        return $this->set('category', $category);
    }

    public function image(string $repository): static
    {
        return $this->set('image', $repository);
    }

    /**
     * @param  list<array{value: string, label: string, recovery?: bool}>  $lines
     */
    public function versions(string $default, array $lines): static
    {
        return $this->set('default_line', $default)->set('lines', $lines);
    }

    public function failoverHint(string $hint): static
    {
        return $this->set('failover_hint', $hint);
    }

    public function serviceRecord(string $prefix, string $options): static
    {
        return $this->set('service_record', ['prefix' => $prefix, 'options' => $options]);
    }

    public function publicCertificate(): static
    {
        return $this->set('public_certificate', true);
    }

    public function persistenceChoice(): static
    {
        return $this->set('persistence_choice', true);
    }

    protected function config(): string
    {
        return 'engine';
    }
}
