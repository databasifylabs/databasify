<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class VersionLine
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $isDefault = false,
        public bool $archives = true,
    ) {}

    /**
     * @return array{value: string, label: string, default: bool}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label, 'default' => $this->isDefault];
    }
}
