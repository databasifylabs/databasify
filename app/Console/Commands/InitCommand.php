<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Setup\EndInterruptedWork;
use App\Actions\Setup\ReleaseInterruptedJobs;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('databasify:init')]
#[Description('End the work an app restart interrupted')]
final class InitCommand extends Command
{
    private const int REDIS_ATTEMPTS = 30;

    private const int REDIS_WAIT_MS = 1000;

    public function handle(ReleaseInterruptedJobs $jobs, EndInterruptedWork $work): int
    {
        $this->attempt('Released interrupted jobs', fn (): int => retry(self::REDIS_ATTEMPTS, fn (): int => $jobs->handle(), self::REDIS_WAIT_MS));
        $this->attempt('Ended interrupted database operations', fn (): int => $work->endDatabaseOperations());
        $this->attempt('Failed interrupted server setups', fn (): int => $work->failServerSetups());
        $this->attempt('Failed interrupted server logs', fn (): int => $work->failRunningLogs());

        return self::SUCCESS;
    }

    /**
     * @param  Closure(): int  $step
     */
    private function attempt(string $done, Closure $step): void
    {
        try {
            $this->line("{$done}: {$step()}.");
        } catch (Throwable $exception) {
            report($exception);
            $this->warn("{$done} failed: {$exception->getMessage()}");
        }
    }
}
