<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Engines\Scripts\Shell;
use App\Models\FileBackupDestination;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class TestFileBackupDestination
{
    public function handle(FileBackupDestination $destination): void
    {
        $primary = $destination->database->primary();

        if ($primary === null) {
            throw ValidationException::withMessages(['storage' => 'The selected database needs a healthy primary to test file storage.']);
        }

        try {
            $primary->server->ssh()->exec('ssh.backups.probe', ['destination' => $destination, 'sh' => new Shell, 'probe' => Str::lower(Str::random(24))], timeout: 180, secret: true);
        } catch (Throwable) {
            throw ValidationException::withMessages(['storage' => 'Connection check failed. Verify host, port, credentials, directory permissions and SFTP host key. The database server needs rclone installed.']);
        }

        $destination->verified_at = now();
    }
}
