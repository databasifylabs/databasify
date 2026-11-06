<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Models\Database;
use App\Services\Images\ImageResolver;

final readonly class CheckDatabaseUpgrade
{
    public function __construct(private ImageResolver $newest) {}

    public function handle(Database $database): ?EngineImage
    {
        $newest = $this->newest->newest($database, $database->version_line);
        $image = $newest->replaces($database->image()) ? $newest : null;
        $database->recordAvailableImage($image);

        return $image;
    }
}
