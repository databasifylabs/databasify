<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Models\Database;

final readonly class RestoreArchive
{
    public function handle(Database $database): void
    {
        $source = $database->settings->restore;
        $archive = $database->provider()->archive();

        if ($source === null || $archive === null) {
            return;
        }

        $archive->restore($source);
    }
}
