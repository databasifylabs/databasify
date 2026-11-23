<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\ExtensionChangeAction;

final readonly class ExtensionChange
{
    public function __construct(
        public ?string $schema,
        public string $name,
        public ExtensionChangeAction $action,
        public ?string $targetVersion = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $schema = $data['schema'] ?? null;
        $name = $data['name'] ?? null;
        $action = ExtensionChangeAction::tryFrom(is_string($data['action'] ?? null) ? $data['action'] : '');
        $targetVersion = $data['target_version'] ?? null;

        if (! is_string($name) || $action === null) {
            return null;
        }

        return new self(is_string($schema) ? $schema : null, $name, $action, is_string($targetVersion) ? $targetVersion : null);
    }

    /**
     * @return array{schema: string|null, name: string, action: string, target_version: string|null}
     */
    public function toArray(): array
    {
        return ['schema' => $this->schema, 'name' => $this->name, 'action' => $this->action->value, 'target_version' => $this->targetVersion];
    }
}
