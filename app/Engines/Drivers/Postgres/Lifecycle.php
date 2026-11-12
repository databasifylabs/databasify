<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Drivers\TlsPair;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\NodeBootstrap;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeWake;
use App\Engines\Requests\TlsInstall;
use App\Engines\Responses\Done;
use App\Engines\Responses\Operation;
use App\Models\DatabaseNode;

final readonly class Lifecycle
{
    public function __construct(
        private EngineScripts $scripts,
        private Accounts $accounts,
    ) {}

    public function start(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/nodes/start', $this->accounts->dataFor($node), timeout: 300);

        return new Done;
    }

    public function wake(DatabaseNode $node, NodeWake $request): Done
    {
        $this->scripts->run($node, 'postgres/nodes/wake', $this->accounts->dataFor($node), timeout: 300, env: ['ALL' => $request->all ? 'true' : 'false']);

        return new Done;
    }

    public function restart(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/nodes/restart', $this->accounts->dataFor($node), timeout: 300);

        return new Done;
    }

    public function bootstrap(DatabaseNode $node, NodeBootstrap $request): Done
    {
        $passwords = PostgresFiles::passwordsOf($request->secrets);

        $this->scripts->run($node, 'postgres/nodes/bootstrap', $this->accounts->dataFor($node), env: [
            'ADMIN_PASSWORD' => $passwords['admin'],
            'REPLICATION_PASSWORD' => $passwords['replication'],
            'POOLER_PASSWORD' => $passwords['pooler'],
            'ACCOUNT_DATABASE' => $request->account->database ?? '',
            'ACCOUNT_USER' => $request->account->user ?? '',
            'ACCOUNT_PASSWORD' => $request->account->password ?? '',
            'RESERVED_DATABASES' => json_encode(Accounts::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ]);

        return new Done;
    }

    public function installTls(DatabaseNode $node, TlsInstall $request): Done
    {
        TlsPair::check($request);
        $this->scripts->run($node, 'postgres/nodes/tls-install', $this->accounts->dataFor($node), env: ['CERTIFICATE' => $request->certificate, 'KEY' => $request->key, 'CA' => $request->ca]);

        return new Done;
    }

    public function ready(DatabaseNode $node, NodeReady $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'postgres/nodes/ready', $this->accounts->dataFor($node), env: ['EXPECT_PRIMARY' => $request->expectPrimary ? 'true' : 'false']);
    }

    public function enableCluster(DatabaseNode $node, ClusterEnable $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'postgres/cluster/enable', $this->accounts->dataFor($node));
    }
}
