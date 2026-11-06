<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\ContainerSpec;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\Persistence;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;
use App\Support\Scripts\Literal;

readonly class EngineScriptData extends EngineGroup
{
    private const int READY_DEADLINE_SECONDS = 21000;

    /**
     * @return array<string, mixed>
     */
    public function for(?DatabaseNode $node = null): array
    {
        return [
            'directory' => $this->engine->directory(),
            'clientPort' => $this->engine->ports()->client,
            'privateIp' => $node?->privateIp(),
            'processes' => $this->engine->processes($node)->names(),
            'readOnly' => $node?->isReadOnly() ?? false,
            'seed' => $node?->isSeed() ?? false,
            'clustered' => $this->database->isClustered(),
            'primary' => $this->database->primaryIp(),
            'cleanup' => $this->engine->scriptView('lifecycle.cleanup'),
            'container' => $this->engine->container(),
            'repository' => $this->database->image_repository,
            'minimumWrapper' => config()->string('databasify.images.minimum_wrapper'),
            ...$this->engine->scriptVariables(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versions(?DatabaseNode $node = null): array
    {
        return [...$this->for($node), 'line' => $this->engine->versionLine()];
    }

    /**
     * @return array<string, mixed>
     */
    public function starting(DatabaseNode $node, bool $bootstrapsCluster): array
    {
        return [
            ...$this->versions($node),
            'bootstrapsCluster' => $bootstrapsCluster,
            'secrets' => $this->database->settings->secrets,
            'donor' => $this->engine->cluster()->cloneDonorFor($node)?->privateIp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(DatabaseNode $node, bool $expectPrimary): array
    {
        return [...$this->for($node), 'expectPrimary' => $expectPrimary, 'deadline' => self::READY_DEADLINE_SECONDS];
    }

    /**
     * @return array<string, mixed>
     */
    public function waking(DatabaseNode $node): array
    {
        return [...$this->for($node), Ssh::START_CONTAINER => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function clustering(DatabaseNode $node): array
    {
        return [...$this->versions($node), ...$this->engine->membershipVariables()];
    }

    /**
     * @return array<string, mixed>
     */
    public function admission(DatabaseNode $node): array
    {
        $donor = $this->engine->cluster()->cloneDonorFor($node);

        return [
            ...$this->for(),
            'node' => $node->privateIp(),
            'readOnly' => $node->isReadOnly(),
            'instance' => $this->engine->clientAddress($node),
            'alias' => $this->database->serviceRecords()?->aliasOf($node),
            'donor' => $donor?->privateIp(),
            'donorInstance' => $donor === null ? null : $this->engine->clientAddress($donor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stopping(bool $erase): array
    {
        return [
            ...$this->for(),
            'erase' => $erase,
            'engineUid' => ContainerSpec::ENGINE_UID,
            'engineHostUser' => ContainerSpec::ENGINE_HOST_USER,
            'engineDirectories' => collect(Database::providers())->map(fn (DatabaseDriver $engine): string => $engine->directory())->unique()->implode(' '),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deploy(DatabaseNode $node, bool $fresh = false, ?EngineImage $image = null): array
    {
        $container = ContainerSpec::for($this->database, $node, $image);

        return [
            ...$this->versions($node),
            'prepare' => $this->engine->scriptView('lifecycle.prepare'),
            'engineUser' => $this->engine->systemUser(),
            'engineUid' => ContainerSpec::ENGINE_UID,
            'engineHostUser' => ContainerSpec::ENGINE_HOST_USER,
            'hostTuning' => $this->engine->scriptView('config.host-tuning'),
            'fresh' => $fresh,
            'databaseKey' => $this->database->dataKey(),
            'nodeHosts' => $this->database->hosts(),
            'nodeKey' => $this->database->nodeKeyOf($node),
            'image' => $container->image->reference(),
            'repository' => $container->image->repository,
            'run' => $container->toRunCommand(),
            'files' => $this->deployFilesOf($node),
            ...$this->archiving(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function archiving(): array
    {
        $archive = $this->engine->archive();

        if ($archive === null) {
            return ['archiveEnabled' => false];
        }

        return [...$archive->variables(), 'archiveEnabled' => $this->database->pointInTimeRecovery !== null];
    }

    /**
     * @return array<string, mixed>
     */
    public function restoring(DatabaseNode $node, RecoverySource $source): array
    {
        return [
            ...$this->versions($node),
            ...$this->archiving(),
            'source' => $this->engine->archive()?->restoreSource($source->location()) ?? '',
            'target' => $source->targetForTools(),
            'targetEpoch' => $source->target->getTimestamp(),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function liveConfig(DatabaseNode $node, array $keys): array
    {
        $effective = $this->database->effectiveConfigFor($node);
        $settings = array_intersect_key($effective, array_flip($keys));

        return [
            ...$this->for($node),
            'settings' => $settings,
            'sqlValues' => array_map(Literal::mysqlValue(...), $settings),
            'resets' => array_values(array_diff($keys, array_keys($effective))),
            'persistent' => $this->database->settings->persistence !== Persistence::Off,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(string $stateDir): array
    {
        return [
            ...$this->engine->scriptVariables(),
            'stateDir' => $stateDir,
            'container' => $this->engine->container(),
            'directory' => $this->engine->directory(),
            'clientPort' => $this->engine->ports()->client,
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array{authority: array<string, string>, authorityName: string, serverName: string, subjectAltNames: string, rsa: bool, combined: bool}
     */
    public function certificates(array $names, bool $reuseAuthority = false): array
    {
        return [
            'authority' => $reuseAuthority ? $this->database->certificateAuthority() : [],
            'authorityName' => "{$this->engine->label()} CA",
            'serverName' => basename($this->engine->directory()).'-server',
            'subjectAltNames' => collect($names)->map($this->subjectAltNameOf(...))->implode(','),
            'rsa' => $this->engine->tls()->rsaKey,
            'combined' => in_array('server.pem', $this->engine->tls()->files, true),
        ];
    }

    /**
     * @return list<NodeFile>
     */
    public function tlsFiles(DatabaseNode $node): array
    {
        return array_values(collect($this->database->certificateFilesFor($node))
            ->map(fn (string $content, string $name): NodeFile => NodeFile::secret("{$this->engine->directory()}/tls/{$name}", $content, $this->engine->systemUser()))
            ->all());
    }

    private function subjectAltNameOf(string $name): string
    {
        return filter_var($name, FILTER_VALIDATE_IP) === false ? "DNS:{$name}" : "IP:{$name}";
    }

    /**
     * @return list<NodeFile>
     */
    private function deployFilesOf(DatabaseNode $node): array
    {
        return [
            ...$this->engine->configFiles($node),
            $this->engine->processes($node)->toNodeFile($this->engine->directory()),
            ...$this->tlsFiles($node),
            ...$this->database->archiveFiles(),
        ];
    }
}
