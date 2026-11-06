<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum SelinuxMode: string
{
    case Enforcing = 'enforcing';
    case Permissive = 'permissive';
    case Disabled = 'disabled';

    public static function fromGetenforce(string $output): self
    {
        return self::tryFrom(strtolower(trim($output))) ?? self::Disabled;
    }
}
