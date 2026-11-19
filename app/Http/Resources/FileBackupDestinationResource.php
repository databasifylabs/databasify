<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FileBackupDestination;
use App\Models\FileBackupRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FileBackupDestination */
class FileBackupDestinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'database_id' => $this->database_id, 'database_name' => $this->database->name,
            'protocol' => $this->protocol, 'host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'path' => $this->path,
            'host_key' => $this->host_key, 'enabled' => $this->enabled, 'backup_hour' => $this->backup_hour,
            'verified_at' => $this->verified_at?->toIso8601String(), 'next_backup_at' => $this->next_backup_at?->toIso8601String(),
            'runs' => $this->runs()->latest()->limit(10)->get()->map(fn (FileBackupRun $run): array => [
                'id' => $run->id, 'status' => $run->status, 'remote_file' => $run->remote_file, 'problem' => $run->problem,
                'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
