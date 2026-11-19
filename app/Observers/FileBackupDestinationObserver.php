<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\FileBackupDestination;

final readonly class FileBackupDestinationObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(FileBackupDestination $destination): void
    {
        $this->children->handle($destination->runs());
    }
}
