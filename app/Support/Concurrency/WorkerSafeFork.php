<?php

declare(strict_types=1);

namespace App\Support\Concurrency;

use Spatie\Fork\Fork;

final class WorkerSafeFork extends Fork
{
    protected function listenForSignals(): void {}
}
