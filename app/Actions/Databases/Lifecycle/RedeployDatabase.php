<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

final readonly class RedeployDatabase
{
    /**
     * @param  list<ShouldQueue>  $then
     */
    public function handle(Database $database, string $title, array $then = []): void
    {
        $database->startOperation($title);
        Bus::chain(collect($then)->prepend(new UpgradeDatabaseJob($database, installUpdate: false)))->dispatch();
    }
}
