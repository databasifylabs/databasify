<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Grant;
use App\Engines\Drivers\TlsPair;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\NodeBootstrap;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\TlsInstall;
use App\Engines\Requests\UserCreate;
use App\Engines\Responses\Done;
use App\Engines\Responses\Operation;
use App\Enums\Databases\GrantAccess;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Lifecycle
{
    public function __construct(
        private EngineScripts $scripts,
        private Configuration $configuration,
        private Accounts $accounts,
    ) {}

    public function start(DatabaseNode $node): Done
    {
        return $this->doneAfter($node, 'mongodb/nodes/start');
    }

    public function wake(DatabaseNode $node): Done
    {
        return $this->doneAfter($node, 'mongodb/nodes/wake');
    }

    public function restart(DatabaseNode $node): Done
    {
        return $this->doneAfter($node, 'mongodb/nodes/restart');
    }

    public function bootstrap(DatabaseNode $node, NodeBootstrap $request): Done
    {
        if ($request->secrets->admin === null || $request->secrets->agent === null) {
            throw new InvalidEngineRequest('invalid_request', 'MongoDB needs the admin and agent secrets');
        }

        $saved = $this->configuration->savedRequestOf($node);

        if (! $this->isSeed($saved)) {
            return new Done;
        }

        $this->scripts->run($node, 'mongodb/nodes/bootstrap', timeout: 300, env: [
            'HOST' => MongoDbFiles::hostOf($saved->ip),
            'ADMIN_PASSWORD' => $request->secrets->admin,
            'AGENT_PASSWORD' => $request->secrets->agent,
        ]);

        if ($request->account !== null) {
            $this->accounts->createDatabase($node, new DatabaseCreate($request->account->database));
            $this->accounts->createUser($node, new UserCreate($request->account->user, $request->account->password, [new Grant($request->account->database, GrantAccess::Write)], null));
        }

        return new Done;
    }

    public function installTls(DatabaseNode $node, TlsInstall $request): Done
    {
        TlsPair::check($request);
        $this->scripts->run($node, 'mongodb/nodes/tls-install', env: ['PEM' => rtrim($request->certificate, "\n")."\n".$request->key, 'CA' => $request->ca]);

        return new Done;
    }

    public function ready(DatabaseNode $node, NodeReady $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'mongodb/nodes/ready');
    }

    public function isSeed(NodeConfigure $request): bool
    {
        return ! $request->clustered || (! $request->readOnly && ($request->primary === null || $request->primary === $request->ip));
    }

    private function doneAfter(DatabaseNode $node, string $script): Done
    {
        $this->scripts->run($node, $script, timeout: 300);

        return new Done;
    }
}
