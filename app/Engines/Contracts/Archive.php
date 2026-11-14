<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use Carbon\CarbonImmutable;

interface Archive
{
    public function status(bool $backUpNow): ArchiveStatus;

    public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation;
}
