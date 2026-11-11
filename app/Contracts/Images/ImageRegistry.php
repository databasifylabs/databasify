<?php

declare(strict_types=1);

namespace App\Contracts\Images;

use App\DTOs\Images\ImageLabels;
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

    public function labelsOf(string $repository, string $digest): ImageLabels;
}
