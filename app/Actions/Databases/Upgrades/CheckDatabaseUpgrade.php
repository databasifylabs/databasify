<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Services\Images\ImageResolver;

final readonly class CheckDatabaseUpgrade
{
    public function __construct(private ImageResolver $newest) {}

    public function handle(Database $database): ?EngineImage
    {
        try {
            $newest = $this->newest->newest($database, $database->version_line);
        } catch (ImageUnavailable $unavailable) {
            return $this->unlessRefused($unavailable, $database);
        }

        $image = $newest->replaces($database->image()) ? $newest : null;
        $database->recordAvailableImage($image);
        $database->recordRefusedImage(null);

        return $image;
    }

    private function unlessRefused(ImageUnavailable $unavailable, Database $database): null
    {
        $refused = $unavailable->refused ?? throw $unavailable;

        if ($database->refused_image_digest === $refused->digest) {
            return null;
        }

        $database->recordRefusedImage($refused);
        event(new DatabaseAlertRaised($database, "{$database->name} stays on {$database->engine()->label} {$database->image_release}", $unavailable->getMessage()));

        return null;
    }
}
