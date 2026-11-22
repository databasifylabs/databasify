<?php

declare(strict_types=1);

namespace App\Mcp\Attributes;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAccess
{
    public function __construct(public AccessArea $area, public AccessLevel $level) {}
}
