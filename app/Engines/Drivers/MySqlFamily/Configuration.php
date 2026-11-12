<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\ConfigFiles;
use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\MariaDb\MariaDbSettings;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Engines\Drivers\MySql\MySqlSettings;
use App\Engines\Drivers\NodeState;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\NodeConfigure as NodeConfigureReply;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Configuration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    private const int CONFIGURE_TIMEOUT = 600;

    public function __construct(
        private FamilyScripts $scripts,
        private NodeState $state,
    ) {}

    public function configure(DatabaseNode $node, NodeConfigure $request): NodeConfigureReply
    {
        $engine = FamilyScripts::engineOf($node);
        $line = $node->database->version_line;
        $this->refuseMissingSecrets($engine, $request);
        $catalog = $this->catalogOf($node);
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($node, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $effective = NodeState::withSettings($request, $catalog->effective($tuned, $request->settings));
        $files = $this->filesOf($engine, $effective, $line, false);

        $reply = $this->scripts->run($node, 'mysql-family/nodes/configure', [
            'REQUEST_JSON' => NodeState::requestJsonOf($request),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'FILES' => NodeState::filesJsonOf($files->files),
            'MANIFEST' => base64_encode($files->manifest),
            'MANIFEST_NEW_CLUSTER' => $engine->id === 'mariadb' ? base64_encode($this->filesOf($engine, $effective, $line, true)->manifest) : '',
            'INIT_SQL' => $engine->id === 'mysql' ? "ALTER USER 'root'@'localhost' IDENTIFIED BY ".FamilyFiles::sqlString((string) $request->secrets->admin).";\n" : MariaDbFiles::initStatementsOf($request),
            'INIT_COMMAND' => $this->initCommandOf($engine),
            'SEED' => $this->startsNewCluster($engine, $request) ? 'true' : 'false',
            'GROUPED_BOOT' => $engine->id === 'mysql' && $request->clustered && ! $request->readOnly ? 'true' : 'false',
            'HELPERS' => implode(' ', $this->helpersOf($engine, $request)),
        ], timeout: self::CONFIGURE_TIMEOUT);

        return new NodeConfigureReply($reply->json()->bool('changed'));
    }

    public function catalogOf(DatabaseNode $node): SettingsCatalog
    {
        return FamilyScripts::engineOf($node)->id === 'mysql' ? MySqlSettings::catalog() : MariaDbSettings::catalog();
    }

    public function savedRequestOf(DatabaseNode $node): NodeConfigure
    {
        return $this->state->requestOf($node, self::REQUEST_STATE) ?? throw new EngineBusy('busy', 'run node configure first');
    }

    /**
     * @return array<string, string>
     */
    public function tunedOf(DatabaseNode $node, NodeConfigure $request): array
    {
        $saved = $this->state->tunedOf($node, self::TUNED_STATE);

        if ($saved !== null) {
            return $saved;
        }

        $tuned = $this->catalogOf($node)->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($node, self::TUNED_STATE, $tuned);

        return $tuned;
    }

    /**
     * @return list<string>
     */
    public function helpersOf(MySqlFamilyEngine $engine, NodeConfigure $request): array
    {
        $helpers = $request->archive === null ? [] : [$engine->archiveUnits()['stream']];

        if ($engine->id === 'mariadb' && $request->clustered) {
            $helpers[] = MariaDbFiles::WATCH_UNIT;
        }

        return $helpers;
    }

    private function refuseMissingSecrets(MySqlFamilyEngine $engine, NodeConfigure $request): void
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

    private function filesOf(MySqlFamilyEngine $engine, NodeConfigure $effective, string $line, bool $newCluster): ConfigFiles
    {
        return $engine->id === 'mysql' ? MySqlFiles::of($effective, $line) : MariaDbFiles::of($effective, $line, $newCluster);
    }

    private function initCommandOf(MySqlFamilyEngine $engine): string
    {
        $data = "{$engine->home}/data";
        $init = "{$engine->home}/conf/init.sql";

        return $engine->id === 'mysql'
            ? "/usr/sbin/mysqld --no-defaults --initialize-insecure --user=mysql --datadir={$data} --init-file={$init}"
            : "mariadb-install-db --no-defaults --user=mysql --datadir={$data} --skip-test-db --auth-root-authentication-method=socket --extra-file={$init}";
    }

    private function startsNewCluster(MySqlFamilyEngine $engine, NodeConfigure $request): bool
    {
        return $engine->id === 'mariadb' && MariaDbFiles::galera($request) && ($request->primary === null || $request->primary === $request->ip);
    }
}
