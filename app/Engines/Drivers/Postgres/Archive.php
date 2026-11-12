<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\RestoreStart;
use App\Engines\Responses\ArchiveStatus as ArchiveStatusReply;
use App\Engines\Responses\Operation;
use App\Models\DatabaseNode;

final readonly class Archive
{
    public function __construct(
        private EngineScripts $scripts,
        private Accounts $accounts,
    ) {}

    public function status(DatabaseNode $node, ArchiveStatus $request): ArchiveStatusReply
    {
        $reply = $this->scripts->run($node, 'postgres/archive/status', $this->accounts->dataFor($node), timeout: 900, env: ['BACK_UP_NOW' => $request->backUpNow ? 'true' : 'false']);

        return ArchiveStatusReply::fromPayload($reply->json());
    }

    public function restore(DatabaseNode $node, RestoreStart $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'postgres/restore/start', $this->accounts->dataFor($node), env: [
            'RESTORE_CONFIG' => PostgresFileView::backrestConfig(PostgresFiles::repositoryOf($request->storage), null),
            'TARGET' => $request->target->utc()->format('Y-m-d H:i:s'),
            'LINE' => $node->database->version_line,
        ]);
    }
}
