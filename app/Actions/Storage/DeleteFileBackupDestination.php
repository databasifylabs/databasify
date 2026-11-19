<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\FileBackupDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeleteFileBackupDestination
{
    public function handle(FileBackupDestination $destination): void
    {
        DB::transaction(function () use ($destination): void {
            $locked = FileBackupDestination::query()->lockForUpdate()->findOrFail($destination->id);
            if ($locked->runs()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['storage' => 'Wait for the active backup before removing its destination.']);
            }
            $locked->delete();
        });
    }
}
