<?php

declare(strict_types=1);

namespace App\Actions\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\EngineClient;
use App\Exceptions\Engines\NotPrimary;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RunOnPrimary
{
    public function __construct(
        private EngineClient $engine,
        private RefreshDatabaseStatus $refresh,
    ) {}

    /**
     * @template TReply of EngineReply
     *
     * @param  EngineRequest<TReply>  $request
     * @return TReply
     */
    public function handle(Database $database, EngineRequest $request, ?string $log = null): EngineReply
    {
        try {
            return $this->engine->send($this->primaryOf($database), $request, $log);
        } catch (NotPrimary) {
            $this->refresh->handle($database);

            return $this->engine->send($this->primaryOf($database->refresh()), $request, $log);
        }
    }

    private function primaryOf(Database $database): DatabaseNode
    {
        return $database->nodes()->with('server')->primary()->first() ?? $database->primaryOrSeed();
    }
}
