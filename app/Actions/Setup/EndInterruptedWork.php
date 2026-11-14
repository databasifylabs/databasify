<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Servers\ServerStatus;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerLog;

final readonly class EndInterruptedWork
{
    private const string INTERRUPTED = 'Interrupted by an app restart.';

    public function endDatabaseOperations(): int
    {
        return Database::query()->withoutProject()
            ->whereNotIn('status', [DatabaseStatus::Ready, DatabaseStatus::Failed])
            ->lazyById()
            ->each(fn (Database $database) => $database->abandonOperation())
            ->count();
    }

    public function failServerSetups(): int
    {
        return Server::query()->withoutProject()
            ->whereIn('status', [ServerStatus::Creating, ServerStatus::Pending, ServerStatus::Provisioning])
            ->update(['status' => ServerStatus::Failed]);
    }

    public function failRunningLogs(): int
    {
        return ServerLog::query()->running()->lazyById()->each(fn (ServerLog $log) => $log->fail(self::INTERRUPTED))->count();
    }
}
