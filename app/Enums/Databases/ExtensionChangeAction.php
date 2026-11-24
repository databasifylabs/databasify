<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum ExtensionChangeAction: string
{
    case Adding = 'adding';
    case Updating = 'updating';
    case Removing = 'removing';
}
