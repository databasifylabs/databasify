<?php

declare(strict_types=1);

namespace App\Plugins;

use App\DTOs\Forms\DynamicForm;
use App\Exceptions\Providers\DuplicateProvider;

abstract class AbstractProviderRegistrar
{
    /** @var array<string, mixed> */
    private array $entry = [];

    final public function __construct(private readonly string $id) {}

    abstract protected function config(): string;

    public static function make(string $id): static
    {
        return new static($id);
    }

    public function label(string $label): static
    {
        return $this->set('label', $label);
    }

    /**
     * @param  class-string  $handler
     */
    public function handler(string $handler): static
    {
        return $this->set('handler', $handler);
    }

    public function icon(string $path): static
    {
        return $this->set('icon', $path);
    }

    public function form(DynamicForm $form): static
    {
        return $this->set('form', $form->toArray());
    }

    /**
     * @param  list<string>  $steps
     */
    public function instructions(array $steps): static
    {
        return $this->set('instructions', $steps);
    }

    public function register(): void
    {
        $key = "{$this->config()}.providers.{$this->id}";

        if (config()->has($key)) {
            throw DuplicateProvider::named($this->id);
        }

        config([$key => array_merge(['icon' => "assets/icons/{$this->config()}s/{$this->id}.svg"], $this->entry)]);
    }

    protected function set(string $key, mixed $value): static
    {
        $this->entry[$key] = $value;

        return $this;
    }
}
