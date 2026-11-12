<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Drivers\TlsPair;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeReset;
use App\Engines\Requests\TlsInstall;
use App\Engines\Responses\Done;
use App\Engines\Responses\Operation;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

final readonly class Lifecycle
{
    public function __construct(
        private SentinelScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function start(DatabaseNode $node): Done
    {
        $request = $this->configuration->savedRequestOf($node);
        $this->scripts->run($node, 'nodes/start', ['HOLDS' => $request->clustered && ! $request->readOnly ? 'true' : 'false', 'PERSISTENT' => SentinelFiles::persistent($request->settings) ? 'true' : 'false'], timeout: 300);

        return new Done;
    }

    public function wake(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'nodes/wake', timeout: 300);

        return new Done;
    }

    public function restart(DatabaseNode $node): Done
    {
        $directives = [];

        try {
            $request = $this->configuration->savedRequestOf($node);
            $effective = $this->configuration->effectiveOf($node, $request);

            foreach (SentinelSettings::restartOnlyKeys(SentinelScripts::engineOf($node), $node->database->version_line) as $key) {
                $directives[] = ['name' => SentinelFiles::configKeyOf($key), 'line' => isset($effective[$key]) ? SentinelFiles::directiveOf($key, $effective[$key]) : null];
            }
        } catch (EngineBusy) {
        }

        $this->scripts->run($node, 'nodes/restart', ['DIRECTIVES' => json_encode($directives, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], timeout: 300);

        return new Done;
    }

    public function reset(DatabaseNode $node, NodeReset $request): Done
    {
        $this->scripts->run($node, 'nodes/reset', ['PEERS' => (string) $request->peers, 'KEEP' => implode(' ', ['127.0.0.1', ...$request->hosts])], timeout: 180);

        return new Done;
    }

    public function installTls(DatabaseNode $node, TlsInstall $request): Done
    {
        TlsPair::check($request);
        $this->scripts->run($node, 'nodes/tls-install', ['PEM' => rtrim($request->certificate, "\n")."\n".$request->key, 'CA' => $request->ca]);

        return new Done;
    }

    public function ready(DatabaseNode $node, NodeReady $request): Operation
    {
        $saved = $this->configuration->savedRequestOf($node);

        return $this->scripts->start($node, $request->key, 'nodes/ready', ['CLUSTERED' => $saved->clustered ? 'true' : 'false', 'READ_ONLY' => $saved->readOnly ? 'true' : 'false', 'SELF' => $saved->ip]);
    }
}
