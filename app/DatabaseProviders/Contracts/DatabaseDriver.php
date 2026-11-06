<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Contracts;

use App\DatabaseProviders\Groups\EngineAccounts;
use App\DatabaseProviders\Groups\EngineArchive;
use App\DatabaseProviders\Groups\EngineCluster;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DatabaseProviders\Groups\EngineNodes;
use App\DatabaseProviders\Groups\EngineScriptData;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\EnginePorts;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ProcessManifest;
use App\DTOs\Databases\TlsSettings;
use App\DTOs\Databases\VersionLine;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;

interface DatabaseDriver
{
    public static function id(): string;

    public function label(): string;

    public function icon(): ?string;

    public function category(): string;

    /**
     * @return array{value: string, label: string, icon: ?string, category: string, max_standbys: int, lines: list<array{value: string, label: string, default: bool}>}
     */
    public function toArray(): array;

    public function config(string $key, mixed $default = null): mixed;

    public function directory(): string;

    public function systemUser(): string;

    public function ports(): EnginePorts;

    public function tls(): TlsSettings;

    public function maxStandbys(): int;

    public function maxReadOnlyNodes(): int;

    public function failoverHint(): string;

    public function supportsPersistence(): bool;

    public function container(): string;

    public function imageRepository(): string;

    /**
     * @return Collection<int, VersionLine>
     */
    public function versions(): Collection;

    /**
     * @return view-string
     */
    public function scriptView(string $name): string;

    public function versionLine(): string;

    public function connection(): ConnectionProfile;

    public function clientAddress(DatabaseNode $node): string;

    public function processes(?DatabaseNode $node = null): ProcessManifest;

    /**
     * @return list<NodeFile>
     */
    public function configFiles(DatabaseNode $node): array;

    /**
     * @return list<ConfigField>
     */
    public function fields(): array;

    /**
     * @return array<string, string>
     */
    public function tunedSettings(NodeResources $resources): array;

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    public function canFailover(Collection $nodes): bool;

    /**
     * @return array<string, string>
     */
    public function generateSecrets(): array;

    /**
     * @return array<string, string>
     */
    public function environment(DatabaseEndpoints $endpoints): array;

    /**
     * @return array<string, mixed>
     */
    public function scriptVariables(): array;

    public function role(string $output): NodeRole;

    /**
     * @return array<string, mixed>
     */
    public function membershipVariables(): array;

    public function nodes(): EngineNodes;

    public function cluster(): EngineCluster;

    public function accounts(): ?EngineAccounts;

    public function archive(): ?EngineArchive;

    public function migration(): ?EngineMigration;

    public function scriptData(): EngineScriptData;
}
