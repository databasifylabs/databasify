<?php

declare(strict_types=1);

namespace App\Console\Commands\Engines;

use App\Actions\Engines\RunEngineConformance;
use App\DTOs\Engines\ConformanceStep;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('engines:conformance {database : The database to run the checks against}')]
#[Description('Run the account conformance checks through the PHP engine driver against a live database')]
final class EngineConformanceCommand extends Command
{
    public function handle(RunEngineConformance $conformance): int
    {
        $database = Database::query()->whereKey($this->argument('database'))->orWhere('name', $this->argument('database'))->firstOrFail();
        config(['databasify.php_engines' => [...config()->array('databasify.php_engines'), $database->engine]]);
        $steps = $conformance->handle($database);

        foreach ($steps as $step) {
            $this->line(($step->passed() ? '<info>PASS</info> ' : '<error>FAIL</error> ').$step->name.($step->passed() ? '' : ": {$step->failure}"));
        }

        return collect($steps)->every(fn (ConformanceStep $step): bool => $step->passed()) ? self::SUCCESS : self::FAILURE;
    }
}
