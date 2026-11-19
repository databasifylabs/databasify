<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\FileBackupDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveFileBackupDestination
{
    public function __construct(private TestFileBackupDestination $test) {}

    public function handle(FileBackupDestination $candidate): void
    {
        if (($candidate->credentials['password'] ?? '') === '' || ($candidate->credentials['encryption_password'] ?? '') === '') {
            throw ValidationException::withMessages(['storage' => 'Re-enter the server password and backup encryption passphrase. Saved credentials are unavailable.']);
        }
        $this->test->handle($candidate);
        DB::transaction(function () use ($candidate): void {
            $locked = FileBackupDestination::query()->lockForUpdate()->find($candidate->id);

            if ($locked?->runs()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['storage' => 'Wait for the active backup before changing its destination.']);
            }

            if ($locked?->runs()->where('status', 'succeeded')->exists()) {
                foreach (['database_id', 'protocol', 'host', 'port', 'username', 'path'] as $field) {
                    if ($locked->getAttribute($field) !== $candidate->getAttribute($field)) {
                        throw ValidationException::withMessages(['storage' => 'This destination has completed backups. Create another destination to change its database or location.']);
                    }
                }
            }

            $candidate->next_backup_at = $candidate->nextBackup();
            $candidate->save();
        });
    }
}
