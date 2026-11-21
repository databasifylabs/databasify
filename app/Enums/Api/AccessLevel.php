<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum AccessLevel: string
{
    case None = 'none';
    case Read = 'read';
    case Write = 'write';
}
