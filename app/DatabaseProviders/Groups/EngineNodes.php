<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Metrics\NodeMetrics;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;

readonly class EngineNodes extends EngineGroup
{
    public function start(DatabaseNode $node, bool $bootstrapsCluster): void
    {
        $this->runStep($node, 'lifecycle.start', $this->scriptData()->starting($node, $bootstrapsCluster), "{$node->server->name}: starting", Ssh::LONG_TIMEOUT);
    }

    public function waitUntilReady(DatabaseNode $node, bool $expectPrimary, string $log): void
    {
        $this->runStep($node, 'lifecycle.wait-ready', $this->scriptData()->readiness($node, $expectPrimary), $log, Ssh::LONG_TIMEOUT);
    }

    public function reset(DatabaseNode $node, int $peers): void {}

    public function skipsRestartOnSameConfig(): bool
    {
        return false;
    }

    public function runsCurrentConfig(DatabaseNode $node): bool
    {
        return false;
    }

    public function runsImage(DatabaseNode $node, EngineImage $image): bool
    {
        $output = $node->ssh()->exec('ssh.databases.containers.running-image', $this->scriptData()->for($node), "{$node->server->name}: reading the running image");

        return str_contains($output, "image={$image->reference()}\n");
    }

    public function finishBootstrap(DatabaseNode $seed): void
    {
        $settings = $this->database->settings;

        $this->runStep($seed, 'lifecycle.after-bootstrap', [
            ...$this->scriptData()->for(),
            'secrets' => $settings->secrets,
            'schema' => $settings->defaultSchema(),
            'username' => $settings->defaultUsername(),
            'sql' => $this->engine->accounts()?->defaultAccountSql() ?? '',
        ], "{$seed->server->name}: creating the default account");
    }

    public function status(DatabaseNode $node, ?string $log = null): NodeStatus
    {
        return NodeStatus::from($this->engine, $this->runStep($node, 'lifecycle.status', $this->scriptData()->for($node), $log, Ssh::PROBE_TIMEOUT));
    }

    public function wake(DatabaseNode $node): void
    {
        $this->runStep($node, 'lifecycle.wake', $this->scriptData()->waking($node), "{$node->server->name}: starting the engine so its data is compared");
    }

    /**
     * @param  list<string>  $keys
     */
    public function applyConfig(DatabaseNode $node, array $keys): void
    {
        $this->runStep($node, 'config.apply-config', $this->scriptData()->liveConfig($node, $keys), "{$node->server->name}: applying settings without a restart");
    }

    public function applyPools(DatabaseNode $node): void {}

    public function syncConfig(DatabaseNode $primary): void {}

    public function reloadCertificates(DatabaseNode $node): void
    {
        $node->ssh()->exec('ssh.databases.certificates.refresh', [
            ...$this->scriptData()->for(),
            'files' => $this->scriptData()->tlsFiles($node),
            'reload' => $this->engine->scriptView('config.reload-tls'),
        ], "{$node->server->name}: reloading certificates");
    }

    public function metrics(DatabaseNode $node): NodeMetrics
    {
        return NodeMetrics::fromOutput($node, $node->ssh()->exec('ssh.databases.monitoring.metrics', [
            ...$this->scriptData()->for(),
            'engineHelpers' => $this->engine->scriptView('helpers'),
            'engineMetrics' => $this->engine->scriptView('monitoring.metrics'),
        ]));
    }
}
