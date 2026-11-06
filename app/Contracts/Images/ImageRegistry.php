<?php

declare(strict_types=1);

namespace App\Contracts\Images;

use App\Services\Images\OciRegistry;
use Illuminate\Container\Attributes\Bind;

#[Bind(OciRegistry::class)]
interface ImageRegistry
{
    /**
     * @return list<string>
     */
    public function tagsOf(string $repository): array;

    public function digestOf(string $repository, string $tag): string;
}
