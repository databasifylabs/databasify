<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\TlsPair;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineBusy;

final readonly class SentinelNodes implements Nodes
{
    private const int LIFECYCLE_TIMEOUT = 300;

    public function __construct(
        private SentinelDriver $driver,
        private SentinelScripts $scripts,
        private SentinelConfiguration $configuration,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->configuration->configure($config);
    }

    public function start(): void
    {
        $request = $this->configuration->savedRequestOf();

        $this->scripts->run($this->driver, 'nodes.start', [
            'HOLDS' => $request->clustered && ! $request->readOnly ? 'true' : 'false',
            'PERSISTENT' => SentinelFiles::persistent($request->settings) ? 'true' : 'false',
        ], self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $this->scripts->run($this->driver, 'nodes.wake', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function restart(): void
    {
        $this->scripts->run($this->driver, 'nodes.restart', ['DIRECTIVES' => json_encode($this->restartDirectives(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], self::LIFECYCLE_TIMEOUT);
    }

    public function reset(int $peers, array $hosts): void
    {
        $this->scripts->run($this->driver, 'nodes.reset', ['PEERS' => (string) $peers, 'KEEP' => implode(' ', ['127.0.0.1', ...$hosts])], 180);
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void {}

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->scripts->run($this->driver, 'nodes.tls-install', ['PEM' => rtrim($certificate, "\n")."\n".$key, 'CA' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->scripts->start($this->driver, $key, 'nodes.ready', [
            'CLUSTERED' => $saved->clustered ? 'true' : 'false',
            'READ_ONLY' => $saved->readOnly ? 'true' : 'false',
            'SELF' => $saved->ip,
        ]);
    }

    public function status(): NodeStatus
    {
        $reply = $this->scripts->read($this->driver, 'nodes.status');

        return NodeStatus::reported(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }

    public function metrics(): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->scripts->read($this->driver, 'nodes.metrics'));
    }

    /**
     * @return list<array{name: string, line: string|null}>
     */
    private function restartDirectives(): array
    {
        try {
            $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
        } catch (EngineBusy) {
            return [];
        }

        return array_map(
            fn (string $key): array => ['name' => SentinelFiles::configKeyOf($key), 'line' => isset($effective[$key]) ? SentinelFiles::directiveOf($key, $effective[$key]) : null],
            $this->configuration->restartOnlyKeys(),
        );
    }
}
