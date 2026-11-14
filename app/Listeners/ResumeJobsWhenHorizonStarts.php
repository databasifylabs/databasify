<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Setup\ResumeInterruptedJobs;
use Laravel\Horizon\Events\MasterSupervisorDeployed;

final readonly class ResumeJobsWhenHorizonStarts
{
    public function __construct(private ResumeInterruptedJobs $resume) {}

    public function handle(MasterSupervisorDeployed $event): void
    {
        $this->resume->handle();
    }
}
