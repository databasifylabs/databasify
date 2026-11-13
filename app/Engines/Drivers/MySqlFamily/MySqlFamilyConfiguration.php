<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\ConfigFiles;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\MariaDb\MariaDbSettings;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Engines\Drivers\MySql\MySqlSettings;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MySqlFamilyConfiguration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    private const int CONFIGURE_TIMEOUT = 600;

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private NodeState $state,
    ) {}

    public function configure(NodeConfig $request): void
    {
        $engine = $this->driver->engine();
        $this->refuseMissingSecrets($engine, $request);
        $catalog = $this->catalogOf();
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($this->driver, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));

        $this->scripts->run($this->driver, 'mysql-family/nodes/configure', [
            ...$this->filesEnvOf($request, $tuned),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'INIT_SQL' => $this->initSqlOf($engine, $request),
            'INIT_COMMAND' => $this->initCommandOf($engine),
            'SEED' => $this->startsNewCluster($engine, $request) ? 'true' : 'false',
            'GROUPED_BOOT' => $engine->id === 'mysql' && $request->clustered && ! $request->readOnly ? 'true' : 'false',
            'HELPERS' => implode(' ', $this->helpersOf($engine, $request)),
        ], timeout: self::CONFIGURE_TIMEOUT);
    }

    /**
     * @param  array<string, string>  $tuned
     * @return array{REQUEST_JSON: string, FILES: string, MANIFEST: string, MANIFEST_NEW_CLUSTER: string}
     */
    public function filesEnvOf(NodeConfig $request, array $tuned): array
    {
        $engine = $this->driver->engine();
        $line = $this->driver->node->database->version_line;
        $effective = NodeState::withSettings($request, $this->catalogOf()->effective($tuned, $request->settings));
        $files = $this->filesOf($engine, $effective, $line, false);

        return [
            'REQUEST_JSON' => NodeState::requestJsonOf($request),
            'FILES' => NodeState::filesJsonOf($files->files),
            'MANIFEST' => base64_encode($files->manifest),
            'MANIFEST_NEW_CLUSTER' => $engine->id === 'mariadb' ? base64_encode($this->filesOf($engine, $effective, $line, true)->manifest) : '',
        ];
    }

    public function catalogOf(): SettingsCatalog
    {
        return $this->driver->engine()->id === 'mysql' ? MySqlSettings::catalog() : MariaDbSettings::catalog();
    }

    public function savedRequestOf(): NodeConfig
    {
        return $this->state->requestOf($this->driver, self::REQUEST_STATE) ?? throw new EngineBusy('busy', 'run node configure first');
    }

    /**
     * @return array<string, string>
     */
    public function tunedOf(NodeConfig $request): array
    {
        return $this->state->tunedOrNewOf($this->driver, self::TUNED_STATE, $this->catalogOf(), $request);
    }

    /**
     * @return list<string>
     */
    public function helpersOf(MySqlFamilyEngine $engine, NodeConfig $request): array
    {
        $helpers = $request->archive === null ? [] : [$engine->archiveUnits()['stream']];

        if ($engine->id === 'mariadb' && $request->clustered) {
            $helpers[] = MariaDbFiles::WATCH_UNIT;
        }

        return $helpers;
    }

    private function refuseMissingSecrets(MySqlFamilyEngine $engine, NodeConfig $request): void
    {
        $label = $engine->label;

        $missing = match (true) {
            $request->secrets->admin === null => "{$label} needs the admin secret",
            $engine->id === 'mariadb' && MariaDbFiles::galera($request) && $request->secrets->replication === null => 'MariaDB clusters need the replication secret',
            $request->archive !== null && $request->secrets->agent === null => "{$label} backups need the agent secret",
            default => null,
        };

        if ($missing !== null) {
            throw new InvalidEngineRequest('invalid_request', $missing);
        }
    }

    private function filesOf(MySqlFamilyEngine $engine, NodeConfig $effective, string $line, bool $newCluster): ConfigFiles
    {
        return $engine->id === 'mysql' ? MySqlFiles::of($effective, $line) : MariaDbFiles::of($effective, $line, $newCluster);
    }

    private function initSqlOf(MySqlFamilyEngine $engine, NodeConfig $request): string
    {
        if ($engine->id !== 'mysql') {
            return MariaDbFiles::initStatementsOf($request);
        }

        return "ALTER USER 'root'@'localhost' IDENTIFIED BY ".FamilyFiles::sqlString((string) $request->secrets->admin).";\n";
    }

    private function initCommandOf(MySqlFamilyEngine $engine): string
    {
        $data = "{$engine->home}/data";
        $init = "{$engine->home}/conf/init.sql";

        return $engine->id === 'mysql'
            ? "/usr/sbin/mysqld --no-defaults --initialize-insecure --user=mysql --datadir={$data} --init-file={$init}"
            : "mariadb-install-db --no-defaults --user=mysql --datadir={$data} --skip-test-db --auth-root-authentication-method=socket --extra-file={$init}";
    }

    private function startsNewCluster(MySqlFamilyEngine $engine, NodeConfig $request): bool
    {
        return $engine->id === 'mariadb' && MariaDbFiles::galera($request) && ($request->primary === null || $request->primary === $request->ip);
    }
}
