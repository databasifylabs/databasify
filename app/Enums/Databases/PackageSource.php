<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum PackageSource: string
{
    case Official = 'official';
    case Repository = 'repository';
    case Upload = 'upload';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Official repository',
            self::Repository => 'Package repository',
            self::Upload => 'Upload',
        };
    }
}
