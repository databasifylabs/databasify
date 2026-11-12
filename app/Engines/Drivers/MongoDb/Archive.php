<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\RestoreStart;
use App\Engines\Responses\ArchiveStatus as ArchiveStatusReply;
use App\Engines\Responses\Operation;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\DatabaseNode;

final readonly class Archive
{
    public function __construct(
        private EngineScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function status(DatabaseNode $node, ArchiveStatus $request): ArchiveStatusReply
    {
        $saved = $this->configuration->savedRequestOf($node);

        if ($saved->archive === null || $saved->secrets->agent === null) {
            throw new EngineUnsupported('unsupported', 'this engine does not implement "archive status"');
        }

        $reply = $this->scripts->run($node, 'mongodb/archive/status', timeout: 900, env: [
            'PBM_CONFIG' => MongoDbFiles::pbmConfigOf($saved->archive->storage, true),
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
        ])->json();
        $failed = ! in_array($reply->string('backupResult'), ['', 'success'], true);
        $status = new ArchiveStatusReply(
            $reply->int('backups'),
            $reply->nullableString('earliest'),
            $reply->nullableString('latest'),
            $reply->nullableString('lastBackup'),
            $failed ? 'The last backup failed, see the '.MongoDbFiles::BACKUP_UNIT.' log.' : $reply->nullableString('problem'),
        );

        if ($request->backUpNow || ($status->backups === 0 && $status->problem === null)) {
            $this->scripts->run($node, 'mongodb/archive/back-up');
        }

        return $status;
    }

    public function restore(DatabaseNode $node, RestoreStart $request): Operation
    {
        $saved = $this->configuration->savedRequestOf($node);

        if ($saved->archive === null || $saved->secrets->agent === null) {
            throw new EngineBusy('busy', 'configure archive on this database before restoring into it');
        }

        return $this->scripts->start($node, $request->key, 'mongodb/restore/start', env: [
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
            'RESTORE_CONFIG' => MongoDbFiles::pbmConfigOf($request->storage, false),
            'SAVED_CONFIG' => MongoDbFiles::pbmConfigOf($saved->archive->storage, false),
            'TARGET' => $request->target->utc()->format('Y-m-d\TH:i:s'),
            'ADMIN_PASSWORD' => (string) $saved->secrets->admin,
            'AGENT_PASSWORD' => $saved->secrets->agent,
        ]);
    }
}
