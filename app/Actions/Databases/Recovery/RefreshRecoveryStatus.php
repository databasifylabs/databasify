<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\ArchiveStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseNode;
use App\Models\PointInTimeRecovery;
use Throwable;

final readonly class RefreshRecoveryStatus
{
    public function handle(PointInTimeRecovery $recovery, bool $backUpNow = false): ArchiveStatus
    {
        $status = $this->statusOf($recovery, $backUpNow)->keepingLatestOf($recovery->latest_restorable_at);
        $recovery->recordStatus($status);

        return $status;
    }

    private function statusOf(PointInTimeRecovery $recovery, bool $backUpNow): ArchiveStatus
    {
        $database = $recovery->database;
        $primary = $database->primary();

        if ($primary === null) {
            return ArchiveStatus::unreadable("{$database->name} has no healthy primary to read the archive from.");
        }

        $archive = $database->engineArchive();

        try {
            return $archive->status($primary, $backUpNow);
        } catch (SshConnectionFailed|SshAccessDenied|RemoteScriptFailed) {
            return $this->unreachable($primary);
        } catch (Throwable $exception) {
            report($exception);

            return $this->unreachable($primary);
        }
    }

    private function unreachable(DatabaseNode $primary): ArchiveStatus
    {
        return ArchiveStatus::unreadable("Could not reach {$primary->server->name} to read the archive.");
    }
}
