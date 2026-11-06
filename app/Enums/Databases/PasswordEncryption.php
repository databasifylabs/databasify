<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum PasswordEncryption: string
{
    case Default = 'caching_sha2_password';
    case Legacy = 'mysql_native_password';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Default - MySQL 8+',
            self::Legacy => 'Legacy - MySQL 5.x',
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }
}
