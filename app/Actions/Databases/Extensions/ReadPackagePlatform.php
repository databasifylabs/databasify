<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\PackagePlatform;
use App\Models\Database;
use App\Support\Images\ImageCache;
use LogicException;

final readonly class ReadPackagePlatform
{
    public function __construct(private ImageCache $cache) {}

    public function handle(Database $database): PackagePlatform
    {
        $extensions = $database->driver()->extensions();

        return new PackagePlatform(
            $this->cache->operatingSystemOf($database->image()->digest, fn (): string => $extensions->operatingSystem()),
            "{$database->engine()->label} {$database->version_line}",
            $extensions->officialRepository(),
            $database->facts()->packageFormat ?? throw new LogicException("{$database->engine()->label} has no package format."),
            $database->nodeArchitectures(),
        );
    }
}
