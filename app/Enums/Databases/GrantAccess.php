<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum GrantAccess: string
{
    case Read = 'read';
    case Write = 'write';
}
