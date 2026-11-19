<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Engines\Scripts\Shell;
use App\Models\FileBackupRun;
use Illuminate\Validation\ValidationException;

final readonly class RunFileBackup
{
    public function handle(FileBackupRun $run): void
    {
        if (FileBackupRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }

        $destination = $run->destination;
        $database = $destination->database;
        $primary = $database->primary();
        $engine = $database->engine()->id;

        if (($destination->credentials['password'] ?? '') === '' || ($destination->credentials['encryption_password'] ?? '') === '') {
            throw ValidationException::withMessages(['backup' => 'Backup credentials are unavailable. Re-enter them before retrying.']);
        }

        if ($primary === null || $database->isBusy() || ! in_array($engine, ['postgres', 'mysql', 'mariadb'], true)) {
            throw ValidationException::withMessages(['backup' => 'File exports require an idle PostgreSQL, MySQL or MariaDB database with a healthy primary.']);
        }

        $filename = now()->utc()->format('Ymd\THis\Z').'-'.$run->id.'.sql.gz.gpg';
        $manifest = json_encode(['format' => 'databasify-sql-export-v1', 'database' => $database->name, 'engine' => $engine, 'version_line' => $database->version_line, 'file' => $filename, 'created_at' => now()->utc()->toIso8601String(), 'restore_verified' => false], JSON_THROW_ON_ERROR);
        $primary->server->ssh()->exec('ssh.backups.export', ['destination' => $destination, 'engine' => $engine, 'container' => 'databasify-'.$engine, 'line' => $database->version_line, 'port' => $database->clientPort(), 'filename' => $filename, 'manifest' => $manifest, 'sh' => new Shell], timeout: 3500, secret: true);
        $run->update(['status' => 'succeeded', 'remote_file' => rtrim($destination->path, '/').'/databasify/'.$destination->id.'/'.$filename, 'finished_at' => now(), 'problem' => null]);
    }
}
