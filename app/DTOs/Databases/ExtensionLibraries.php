<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class ExtensionLibraries
{
    /**
     * @param  list<string>  $libraries
     * @param  array<string, string>  $schemas
     */
    public function __construct(
        public array $libraries = [],
        public array $schemas = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $libraries = is_array($data['libraries'] ?? null) ? array_values(array_filter($data['libraries'], is_string(...))) : [];
        $schemas = is_array($data['schemas'] ?? null) ? $data['schemas'] : [];

        return new self($libraries, array_filter($schemas, fn (mixed $schema, int|string $library): bool => is_string($schema) && is_string($library), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @return array{libraries: list<string>, schemas: array<string, string>}
     */
    public function toArray(): array
    {
        return ['libraries' => $this->libraries, 'schemas' => $this->schemas];
    }

    public function has(string $library): bool
    {
        return in_array($library, $this->libraries, true);
    }

    public function schemaOf(string $library): ?string
    {
        return $this->schemas[$library] ?? null;
    }

    public function with(string $library, ?string $schema): self
    {
        return new self(
            $this->has($library) ? $this->libraries : [...$this->libraries, $library],
            $schema === null ? $this->schemas : [...$this->schemas, $library => $schema],
        );
    }

    public function without(string $library): self
    {
        return new self(array_values(array_diff($this->libraries, [$library])), array_diff_key($this->schemas, [$library => true]));
    }
}
