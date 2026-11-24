<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Engines\Data\AvailableExtension;
use App\Enums\Engines\Feature;
use App\Models\Database;

final readonly class ReadAvailableExtensions
{
    /**
     * @return list<AvailableExtension>
     */
    public function handle(Database $database): array
    {
        if (! $database->offers(Feature::Extensions)) {
            return [];
        }

        return $database->driver()->extensions()->available();
    }
}
