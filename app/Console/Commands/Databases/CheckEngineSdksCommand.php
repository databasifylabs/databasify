<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Actions\Databases\Upgrades\FindUnsupportedSdkDatabases;
use App\Models\Database;
use App\Support\Engines\SdkRange;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:check-sdks {--range= : The SDK range of the Databasify release you plan to install}')]
#[Description('List the databases an SDK range would make read-only')]
final class CheckEngineSdksCommand extends Command
{
    public function handle(FindUnsupportedSdkDatabases $find): int
    {
        $range = $this->option('range');
        $constraint = is_string($range) && $range !== '' ? $range : SdkRange::constraint();
        $databases = $find->handle($constraint)->collect();

        if ($databases->isEmpty()) {
            $this->components->info('No database would become read-only.');

            return self::SUCCESS;
        }

        $this->components->warn("These databases would become read-only under SDK {$constraint}:");
        $databases->each(fn (Database $database) => $this->components->bulletList(["{$database->name} runs SDK {$database->imageSdk()}"]));

        return self::SUCCESS;
    }
}
