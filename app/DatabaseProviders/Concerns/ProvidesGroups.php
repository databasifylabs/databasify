<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Concerns;

use App\DatabaseProviders\Groups\EngineAccounts;
use App\DatabaseProviders\Groups\EngineArchive;
use App\DatabaseProviders\Groups\EngineCluster;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DatabaseProviders\Groups\EngineNodes;
use App\DatabaseProviders\Groups\EngineScriptData;

trait ProvidesGroups
{
    final public function nodes(): EngineNodes
    {
        return $this->nodeSteps();
    }

    final public function cluster(): EngineCluster
    {
        return $this->clusterSteps();
    }

    final public function accounts(): ?EngineAccounts
    {
        return $this->accountSteps();
    }

    final public function archive(): ?EngineArchive
    {
        return $this->archiveSteps();
    }

    final public function migration(): ?EngineMigration
    {
        return $this->migrationSteps();
    }

    final public function scriptData(): EngineScriptData
    {
        return $this->scriptDataSteps();
    }

    protected function nodeSteps(): EngineNodes
    {
        return new EngineNodes($this, $this->database);
    }

    protected function clusterSteps(): EngineCluster
    {
        return new EngineCluster($this, $this->database);
    }

    protected function accountSteps(): ?EngineAccounts
    {
        return null;
    }

    protected function archiveSteps(): ?EngineArchive
    {
        return null;
    }

    protected function migrationSteps(): ?EngineMigration
    {
        return null;
    }

    protected function scriptDataSteps(): EngineScriptData
    {
        return new EngineScriptData($this, $this->database);
    }
}
