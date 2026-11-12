<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MariaDb;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\MySqlFamily\Accounts;
use App\Engines\Drivers\MySqlFamily\ConfigCommands;
use App\Engines\Drivers\MySqlFamily\Configuration;
use App\Engines\Drivers\MySqlFamily\Readings;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigFields;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\ConfigSync;
use App\Engines\Requests\ConfigTune;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Requests\NodeMetrics;
use App\Engines\Requests\NodeStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use LogicException;

final readonly class MariaDbDriver implements EngineDriver
{
    public function __construct(
        private Readings $readings,
        private Accounts $accounts,
        private Configuration $configuration,
        private ConfigCommands $config,
    ) {}

    public function answers(EngineRequest $request): bool
    {
        return $request instanceof AccountList
            || $request instanceof DatabaseCreate
            || $request instanceof DatabaseDrop
            || $request instanceof UserCreate
            || $request instanceof UserSetPassword
            || $request instanceof UserGrant
            || $request instanceof UserDrop
            || $request instanceof NodeStatus
            || $request instanceof NodeMetrics
            || $request instanceof NodeConfigure
            || $request instanceof ConfigApply
            || $request instanceof ConfigSync
            || $request instanceof ConfigShow
            || $request instanceof ConfigFields
            || $request instanceof ConfigTune;
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        return match (true) {
            $request instanceof AccountList => $this->accounts->list($node),
            $request instanceof DatabaseCreate => $this->accounts->createDatabase($node, $request),
            $request instanceof DatabaseDrop => $this->accounts->dropDatabase($node, $request),
            $request instanceof UserCreate => $this->accounts->createUser($node, $request),
            $request instanceof UserSetPassword => $this->accounts->setPassword($node, $request),
            $request instanceof UserGrant => $this->accounts->grant($node, $request),
            $request instanceof UserDrop => $this->accounts->dropUser($node, $request),
            $request instanceof NodeStatus => $this->readings->status($node),
            $request instanceof NodeMetrics => $this->readings->metrics($node),
            $request instanceof NodeConfigure => $this->configuration->configure($node, $request),
            $request instanceof ConfigApply => $this->config->apply($node),
            $request instanceof ConfigSync => $this->config->sync(),
            $request instanceof ConfigShow => $this->config->show($node),
            $request instanceof ConfigFields => $this->config->fields($node),
            $request instanceof ConfigTune => $this->config->tune($node),
            default => throw new LogicException("The MariaDB driver does not answer {$request->command()}."),
        };
    }
}
