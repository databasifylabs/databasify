<?php

declare(strict_types=1);

namespace App\DTOs\Projects;

final readonly class NewProject
{
    public function __construct(public string $name) {}
}
