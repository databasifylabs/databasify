<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Actions\Updates\CheckForRelease;
use App\Services\Updates\HostUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('updates:check')]
#[Description('Check get.databasify.io for the latest Databasify release')]
final class CheckForReleaseCommand extends Command
{
    public function handle(CheckForRelease $check, HostUpdater $updater): int
    {
        if (! $updater->isInstalled()) {
            $this->components->info('Updates run only on Docker installs with the updater.');

            return self::SUCCESS;
        }

        try {
            $this->components->info("The latest release is {$check->handle()->version}.");
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
