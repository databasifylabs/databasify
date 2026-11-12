<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Actions\Updates\CheckForRelease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('updates:check')]
#[Description('Check get.databasify.io for the latest Databasify release')]
final class CheckForReleaseCommand extends Command
{
    public function handle(CheckForRelease $check): int
    {
        try {
            $this->components->info("The latest release is {$check->handle()->version}.");
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
