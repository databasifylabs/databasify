<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;

final readonly class PostgresConfiguration
{
    public const string TUNED_STATE = 'conf/tuned.json';

    private const string REQUEST_STATE = 'conf/request.json';

    private const int CONFIGURE_TIMEOUT = 300;

    private const int JOB_MODE = 0o700;

    public function __construct(
        private PostgresDriver $driver,
        private NodeState $state,
        private PostgresFiles $files,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->catalog()->check($config->settings);
        $saved = $this->savedTuned();
        $tuned = $saved ?? $this->catalog()->tuned(NodeState::selfOf($config));
        $files = $this->files->of($this->effectiveOf($config, $tuned), $this->driver->node->database->version_line, $this->poolSizeOf($config));

        $this->driver->run('engines.postgres.nodes.configure', [
            'config' => $config,
            'requestJson' => NodeState::requestJsonOf($config),
            'tunedJson' => $saved === null ? NodeState::tunedJsonOf($tuned) : null,
            'files' => new ConfigFiles([...$files->files, ...$this->jobsFor($config)], $files->manifest),
            'votingPeers' => collect($config->members)
                ->filter(fn (Member $member): bool => $member->voting && $member->ip !== $config->ip)
                ->map(fn (Member $member): string => $member->ip)
                ->values()
                ->all(),
        ], timeout: self::CONFIGURE_TIMEOUT);
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public function effectiveOf(NodeConfig $config, array $tuned): NodeConfig
    {
        return NodeState::withSettings($config, $this->catalog()->effective($tuned, $config->settings));
    }

    public function savedRequestOf(): NodeConfig
    {
        return $this->savedConfig() ?? throw new EngineBusy('run node configure first');
    }

    /**
     * @return list<ConfigFile>
     */
    private function jobsFor(NodeConfig $config): array
    {
        if ($config->archive === null) {
            return [];
        }

        return [new ConfigFile(PostgresManifest::BACKUP_JOB, self::JOB_MODE, $this->driver->script('engines.postgres.jobs.backup'))];
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

    private function poolSizeOf(NodeConfig $config): string
    {
        return (string) max(10, min(100, NodeState::selfOf($config)->cpus * 2));
    }
}
