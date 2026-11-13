<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Settings\SettingsCatalog;

final readonly class PostgresConfiguration
{
    public const string TUNED_STATE = 'conf/tuned.json';

    private const string REQUEST_STATE = 'conf/request.json';

    private const string POOL_SIZE_PLACEHOLDER = '__DEFAULT_POOL_SIZE__';

    private const int CONFIGURE_TIMEOUT = 300;

    private const int JOB_MODE = 0o700;

    public function __construct(
        private PostgresDriver $driver,
        private NodeState $state,
        private PostgresFiles $files,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $line = $this->driver->node->database->version_line;
        $catalog = $this->catalog();
        $catalog->check($config->settings);
        $saved = $this->savedTuned();
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($config));
        $files = $this->files->of(NodeState::withSettings($config, $catalog->effective($tuned, $config->settings)), $line, self::POOL_SIZE_PLACEHOLDER);

        $this->driver->run('postgres/nodes/configure', $this->driver->data(), timeout: self::CONFIGURE_TIMEOUT, env: [
            'REQUEST_JSON' => NodeState::requestJsonOf($config),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'FILES' => NodeState::filesJsonOf([...$files->files, ...$this->jobsFor($config)]),
            'MANIFEST' => base64_encode($files->manifest),
            'CLUSTERED' => $config->clustered ? 'true' : 'false',
            'READ_ONLY' => $config->readOnly ? 'true' : 'false',
            'ARCHIVE' => $config->archive === null ? 'false' : 'true',
            'LINE' => $line,
            'IP' => $config->ip,
            'MEMBERS' => json_encode(array_map(fn (Member $member): array => $member->toPayload(), $config->members), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<ConfigFile>
     */
    private function jobsFor(NodeConfig $config): array
    {
        if ($config->archive === null) {
            return [];
        }

        return [new ConfigFile(PostgresManifest::BACKUP_JOB, self::JOB_MODE, $this->driver->script('postgres/jobs/backup', $this->driver->data()))];
    }

    /**
     * @return array<string, string>|null
     */
    public function savedTuned(): ?array
    {
        return $this->state->tunedOf($this->driver, self::TUNED_STATE);
    }

    public function catalog(): SettingsCatalog
    {
        return PostgresSettings::catalogFor($this->driver->node->database->version_line);
    }

    public function savedConfig(): ?NodeConfig
    {
        return $this->state->requestOf($this->driver, self::REQUEST_STATE);
    }
}
