<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\Grant;
use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\NodeState;
use App\Engines\Drivers\TlsPair;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\NodeBootstrap;
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
    private const int WAKE_TIMEOUT = 1900;

    public function __construct(
        private FamilyScripts $scripts,
        private Accounts $accounts,
        private NodeState $state,
    ) {}

    public function start(DatabaseNode $node): Done
    {
        $this->scripts->run($node, '{engine}/nodes/start', timeout: 300);

        return new Done;
    }

    public function wake(DatabaseNode $node): Done
    {
        $this->scripts->run($node, '{engine}/nodes/wake', timeout: self::WAKE_TIMEOUT);

        return new Done;
    }

    public function restart(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'mysql-family/nodes/restart', timeout: 300);

        return new Done;
    }

    public function bootstrap(DatabaseNode $node, NodeBootstrap $request): Done
    {
        if ($request->secrets->replication === null || $request->secrets->agent === null) {
            throw new InvalidEngineRequest('invalid_request', FamilyScripts::engineOf($node)->label.' needs the replication and agent secrets');
        }

        $seed = $this->scripts->run($node, '{engine}/nodes/bootstrap', ['REPLICATION_PASSWORD' => $request->secrets->replication, 'AGENT_PASSWORD' => $request->secrets->agent])->json()->bool('seed');

        if ($seed && $request->account !== null) {
            $this->accounts->createDatabase($node, new DatabaseCreate($request->account->database));
            $this->accounts->createUser($node, new UserCreate($request->account->user, $request->account->password, [new Grant($request->account->database, GrantAccess::Write)], null));
        }

        return new Done;
    }

    public function installTls(DatabaseNode $node, TlsInstall $request): Done
    {
        TlsPair::check($request);
        $this->scripts->run($node, 'mysql-family/nodes/tls-install', ['CERTIFICATE' => $request->certificate, 'KEY' => $request->key, 'CA' => $request->ca]);

        return new Done;
    }

    public function ready(DatabaseNode $node, NodeReady $request): Operation
    {
        return $this->scripts->start($node, $request->key, '{engine}/nodes/ready', ['MANIFEST' => $this->manifestWithoutNewCluster($node)]);
    }

    public function manifestWithoutNewCluster(DatabaseNode $node): string
    {
        if (FamilyScripts::engineOf($node)->id !== 'mariadb') {
            return '';
        }

        $saved = $this->state->requestOf($node, Configuration::REQUEST_STATE);

        return $saved === null ? '' : base64_encode(MariaDbFiles::of($saved, $node->database->version_line, false)->manifest);
    }
}
