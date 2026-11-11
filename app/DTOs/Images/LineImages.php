<?php

declare(strict_types=1);

namespace App\DTOs\Images;

use App\DTOs\Databases\EngineImage;

final readonly class LineImages
{
    public function __construct(
        public EngineImage $newest,
        public ?EngineImage $supported,
    ) {}
}
