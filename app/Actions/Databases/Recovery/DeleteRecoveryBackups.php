<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\StoredArchive;
use App\Models\Database;

final readonly class DeleteRecoveryBackups
{
    public function __construct(private DeleteArchive $archive) {}

    public function handle(Database $database, StoredArchive $archive): void
    {
        $database->startOperation('Delete backups from the bucket');

        try {
            $this->archive->handle($archive);
        } finally {
            $database->finishOperation();
        }
    }
}
