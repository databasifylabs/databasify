<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDatabaseSettings;
use App\Casts\AsEngineCapabilities;
use App\Casts\AsEngineFields;
use App\Contracts\Projects\Movable;
use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Drivers\AbstractDatabaseDriver;
use App\DatabaseProviders\Groups\EngineAccounts;
use App\DatabaseProviders\Groups\EngineArchive;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\DatabaseHostnames;
use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\IssuedCertificate;
use App\DTOs\Databases\MaintenanceWindow;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ServerAccounts;
use App\DTOs\Databases\ServiceRecords;
use App\DTOs\Databases\VersionLine;
use App\DTOs\Dns\HostPair;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Recovery\RecoverySettings;
use App\DTOs\Recovery\StoredArchive;
use App\Engines\Data\EngineField;
use App\Engines\Data\Grant;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\Persistence;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Exceptions\Databases\MissingCapability;
use App\Exceptions\Providers\UnknownProvider;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Concerns\BelongsToProject;
use App\Models\Concerns\MovesBetweenProjects;
use App\Observers\DatabaseObserver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $engine
 * @property string $version_line
 * @property DatabaseMode $mode
 * @property DatabaseStatus $status
 * @property string|null $operation
 * @property CarbonImmutable|null $operation_started_at
 * @property string $image_repository
 * @property string $image_digest
 * @property string $image_release
 * @property string|null $available_image_repository
 * @property string|null $available_image_digest
 * @property string|null $available_image_release
 * @property CarbonImmutable|null $retune_needed_at
 * @property int $maintenance_day
 * @property int $maintenance_hour
 * @property DatabaseSettings $settings
 * @property EngineCapabilities|null $engine_capabilities
 * @property list<EngineField> $config_fields
 * @property list<string>|null $log_units
 * @property string|null $dns_zone_id
 * @property-read DnsZone|null $dnsZone
 * @property-read PointInTimeRecovery|null $pointInTimeRecovery
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'name', 'engine', 'version_line', 'mode', 'status', 'image_repository', 'image_digest', 'image_release', 'available_image_repository', 'available_image_digest', 'available_image_release', 'retune_needed_at', 'maintenance_day', 'maintenance_hour', 'settings', 'dns_zone_id', 'operation', 'operation_started_at', 'engine_capabilities', 'config_fields', 'log_units'])]
#[Hidden(['settings'])]
#[ObservedBy(DatabaseObserver::class)]
class Database extends Model implements Movable
{
    use BelongsToProject, HasUuids, MovesBetweenProjects;

    private const int STOP_TTL_SECONDS = 86400;

    public const string CERTIFICATE_AUTHORITY = 'ca.crt';

    private const array AUTHORITY_FILES = ['ca.crt', 'ca.key'];

    /**
     * @return BelongsTo<DnsZone, $this>
     */
    public function dnsZone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class);
    }

    public function memberCount(): int
    {
        return $this->nodes()->members()->count();
    }

    public function hasStandby(): bool
    {
        return $this->memberCount() > 1;
    }

    public function clientCaCertificate(): ?string
    {
        return $this->publicCertificateZone() === null ? $this->settings->certificate(self::CERTIFICATE_AUTHORITY) : null;
    }

    public function publicCertificateZone(): ?DnsZone
    {
        return $this->engine()->publicCertificate ? $this->dnsZone : null;
    }

    public function certificatesAreCurrent(): bool
    {
        return collect($this->ownCertificateFiles())->every(fn (string $name): bool => $this->settings->certificate($name) !== '')
            && $this->servesCertificate($this->serverCertificate(), $this->certificateNames());
    }

    public function certificatesFit(): bool
    {
        return $this->certificatesAreCurrent() && $this->hasKeyFor($this->serverCertificate());
    }

    public function allCertificatesCurrent(): bool
    {
        $zone = $this->publicCertificateZone();

        if ($zone !== null) {
            return $zone->hasCurrentCertificate();
        }

        return $this->nodesWithServers()->every(fn (DatabaseNode $node): bool => IssuedCertificate::fromPem($this->settings->nodeCertificate($node->id, 'server.crt') ?? '')?->isCurrentFor($node->ownCertificateNames()) === true);
    }

    /**
     * @param  list<string>  $names
     */
    public function servesCertificate(string $pem, array $names): bool
    {
        $zone = $this->publicCertificateZone();

        if ($zone === null) {
            return IssuedCertificate::fromPem($pem)?->isCurrentFor($names) ?? false;
        }

        return $zone->hasCurrentCertificate() && $pem === $zone->certificateBundle();
    }

    public function hasKeyFor(string $pem): bool
    {
        return ! $this->provider()->tls()->rsaKey || (IssuedCertificate::fromPem($pem)->rsaKey ?? false);
    }

    /**
     * @return list<string>
     */
    public function ownCertificateFiles(): array
    {
        return array_values(array_filter($this->provider()->tls()->files, fn (string $name): bool => $name !== self::CERTIFICATE_AUTHORITY));
    }

    public function serverCertificateFile(): string
    {
        return collect($this->ownCertificateFiles())->first(fn (string $name): bool => ! str_ends_with($name, '.key')) ?? self::CERTIFICATE_AUTHORITY;
    }

    private function serverCertificate(): string
    {
        return $this->settings->certificate($this->serverCertificateFile());
    }

    /**
     * @param  array<string, string>  $certificates
     */
    public function mergeCertificates(array $certificates): void
    {
        $this->update(['settings' => $this->settings->mergingCertificates($certificates)]);
    }

    /**
     * @return array<string, string>
     */
    public function certificateAuthority(): array
    {
        return array_filter(array_combine(self::AUTHORITY_FILES, array_map($this->settings->certificate(...), self::AUTHORITY_FILES)));
    }

    /**
     * @return array<string, string>
     */
    public function certificateFilesFor(DatabaseNode $node): array
    {
        $own = $node->isReadOnly() && $this->publicCertificateZone() === null;

        return collect($this->provider()->tls()->files)
            ->mapWithKeys(fn (string $name): array => [$name => ($own ? $this->settings->nodeCertificate($node->id, $name) : null) ?? $this->settings->certificate($name)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function effectiveConfigFor(DatabaseNode $node): array
    {
        $engine = $this->provider();

        return collect($engine->fields())
            ->mapWithKeys(fn (ConfigField $field): array => [$field->key => $field->default])
            ->merge($engine->tunedSettings($node->resources()))
            ->merge($this->settings->config)
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    public function forgetNodeCertificates(DatabaseNode $node): void
    {
        $this->update(['settings' => $this->settings->withoutNodeCertificates($node->id)]);
    }

    /**
     * @param  array<string, string>  $files
     */
    public function storeNodeCertificates(DatabaseNode $node, array $files): void
    {
        $this->update(['settings' => $this->settings->withNodeCertificates($node->id, array_diff_key($files, array_flip(self::AUTHORITY_FILES)))]);
    }

    public function isClustered(): bool
    {
        return $this->size() > 1;
    }

    public function size(): int
    {
        return $this->nodes()->count();
    }

    public function nodesWithServers(): DatabaseNodeCollection
    {
        return $this->nodes()->get();
    }

    public function membersWithServers(): DatabaseNodeCollection
    {
        return $this->nodes()->members()->get();
    }

    public function nodesOtherThan(DatabaseNode $node): DatabaseNodeCollection
    {
        return $this->nodes()->whereKeyNot($node->id)->get();
    }

    public function readOnlyNodes(): DatabaseNodeCollection
    {
        return $this->nodes()->readOnly()->get();
    }

    public function membersOtherThan(DatabaseNode $node): DatabaseNodeCollection
    {
        return $this->nodes()->members()->whereKeyNot($node->id)->get();
    }

    /**
     * @return SupportCollection<int, Server>
     */
    public function servers(): SupportCollection
    {
        return $this->nodesWithServers()->toBase()->map(fn (DatabaseNode $node): Server => $node->server);
    }

    public function hosts(): string
    {
        return $this->nodesWithServers()->map(fn (DatabaseNode $node): string => $node->privateIp())->implode(',');
    }

    /**
     * @return list<string>
     */
    public function memberIps(): array
    {
        return array_values($this->membersWithServers()->map(fn (DatabaseNode $node): string => $node->privateIp())->all());
    }

    public function memberHosts(): string
    {
        return implode(',', $this->memberIps());
    }

    /**
     * @return list<string>
     */
    public function peerAddresses(): array
    {
        return array_values($this->addressesOf($this->nodesWithServers())->unique()->sort()->all());
    }

    /**
     * @return list<string>
     */
    public function certificateNames(): array
    {
        $memberHosts = $this->serviceRecords()?->memberHostsOf($this->nodesWithServers()) ?? [];

        return array_values(collect($this->hostnames()?->all() ?? [])
            ->merge($memberHosts)
            ->merge($this->addressesOf($this->membersWithServers()))
            ->unique()
            ->all());
    }

    /**
     * @return SupportCollection<int, string>
     */
    private function addressesOf(DatabaseNodeCollection $nodes): SupportCollection
    {
        return $nodes->toBase()->flatMap(fn (DatabaseNode $node): array => [$node->server->address(), $node->privateIp()]);
    }

    public function hostnames(): ?DatabaseHostnames
    {
        return $this->dnsZone === null ? null : $this->hostnamesUnder($this->dnsZone);
    }

    public function hostnamesUnder(DnsZone $zone): DatabaseHostnames
    {
        return new DatabaseHostnames(HostPair::under($zone, $this->name), HostPair::under($zone, DatabaseHostnames::REPLICA_PREFIX.$this->name));
    }

    /**
     * @return list<string>
     */
    public function hostnamesClaimedUnder(DnsZone $zone): array
    {
        $readOnly = $this->readOnlyNodes()->toBase()->flatMap(fn (DatabaseNode $node): array => $node->hostnamesUnder($zone)?->all() ?? []);
        $memberHosts = $this->serviceRecords($zone)?->memberHostsOf($this->nodesWithServers()) ?? [];

        return array_merge($this->hostnamesUnder($zone)->all(), $readOnly->values()->all(), $memberHosts);
    }

    public function serviceRecords(?DnsZone $zone = null): ?ServiceRecords
    {
        $zone ??= $this->dnsZone;
        $prefix = $this->provider()->config('service_record.prefix');
        $options = $this->provider()->config('service_record.options');

        if ($zone === null || ! is_string($prefix) || ! is_string($options)) {
            return null;
        }

        $host = $this->hostnamesUnder($zone)->primary->public;

        return new ServiceRecords($zone, $host, "{$prefix}.{$host}", $options, $this->name, $this->provider()->ports()->client);
    }

    public function moveToZone(?DnsZone $zone): void
    {
        $this->update(['dns_zone_id' => $zone?->id]);
        $this->unsetRelation('dnsZone');
    }

    /**
     * @return HasOne<PointInTimeRecovery, $this>
     */
    public function pointInTimeRecovery(): HasOne
    {
        return $this->hasOne(PointInTimeRecovery::class);
    }

    /**
     * @return HasMany<DatabaseNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(DatabaseNode::class)->chaperone()->with('server.network')->orderBy('position');
    }

    /**
     * @return Collection<int, Server>
     */
    public function movesAlongWith(): iterable
    {
        return Server::query()->withoutProject()->whereIn('id', $this->nodes()->reorder()->select('server_id'))->get();
    }

    /**
     * @return HasMany<DatabaseUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class)->chaperone()->orderBy('name');
    }

    /**
     * @return HasMany<Schema, $this>
     */
    public function schemas(): HasMany
    {
        return $this->hasMany(Schema::class)->chaperone()->orderBy('name');
    }

    /**
     * @return HasMany<DatabaseMigration, $this>
     */
    public function migrations(): HasMany
    {
        return $this->hasMany(DatabaseMigration::class)->latest('id');
    }

    /**
     * @return HasMany<DatabaseMigration, $this>
     */
    public function recentMigrations(): HasMany
    {
        return $this->migrations()->limit(5);
    }

    /**
     * @return HasMany<ConnectionPool, $this>
     */
    public function connectionPools(): HasMany
    {
        return $this->hasMany(ConnectionPool::class)->orderBy('name');
    }

    /**
     * @return HasManyThrough<NodeAlert, DatabaseNode, $this>
     */
    public function openAlerts(): HasManyThrough
    {
        return $this->hasManyThrough(NodeAlert::class, DatabaseNode::class)->whereNull('node_alerts.resolved_at')->oldest('node_alerts.opened_at');
    }

    /**
     * @return HasMany<TrustedSource, $this>
     */
    public function trustedSources(): HasMany
    {
        return $this->hasMany(TrustedSource::class)->orderBy('id');
    }

    public function maintenanceWindow(): MaintenanceWindow
    {
        return new MaintenanceWindow($this->maintenance_day, $this->maintenance_hour);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $query->where(fn (Builder $query) => $query->whereLike('name', "%{$term}%")->orWhereIn('engine', self::enginesLabelledLike($term)));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ready(Builder $query): void
    {
        $query->where('status', DatabaseStatus::Ready);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function operational(Builder $query): void
    {
        $query->whereNotIn('status', [DatabaseStatus::Creating, DatabaseStatus::Failed]);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withoutRunningFlow(Builder $query): void
    {
        $query->whereDoesntHave('flows', fn (Builder $flows) => $flows->running());
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function operationStartedBefore(Builder $query, CarbonInterface $moment): void
    {
        $query->whereNotNull('operation')->where('operation_started_at', '<', $moment);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inMaintenanceWindowAt(Builder $query, CarbonInterface $moment): void
    {
        $utc = $moment->copy()->utc();
        $query->where('maintenance_day', $utc->dayOfWeek)->where('maintenance_hour', $utc->hour);
    }

    public function startOperation(string $title): void
    {
        $claimed = self::query()->whereKey($this->id)->whereNull('operation')->update(['operation' => $title, 'operation_started_at' => now()]);

        $this->refresh();

        if ($claimed === 0) {
            throw $this->busyError();
        }

        $this->forgetStopFlags();
    }

    public function retitleOperation(string $title): void
    {
        self::query()->whereKey($this->id)->whereNotNull('operation')->update(['operation' => $title]);
        $this->forceFill(['operation' => $title])->syncOriginalAttributes(['operation']);
    }

    public function finishOperation(): void
    {
        $idle = ['operation' => null, 'operation_started_at' => null];
        self::query()->whereKey($this->id)->update($idle);
        $this->forceFill($idle)->syncOriginalAttributes(array_keys($idle));
        $this->forgetStopFlags();
    }

    public function requestStop(): void
    {
        Cache::put($this->stopKey(), true, self::STOP_TTL_SECONDS);
    }

    public function isStopRequested(): bool
    {
        return Cache::has($this->stopKey()) && ! Cache::has($this->cleanupKey());
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $cleanup
     * @return TResult
     */
    public function ignoringStop(Closure $cleanup): mixed
    {
        Cache::put($this->cleanupKey(), true, self::STOP_TTL_SECONDS);

        try {
            return $cleanup();
        } finally {
            Cache::forget($this->cleanupKey());
        }
    }

    public function isBusy(): bool
    {
        return $this->operation !== null;
    }

    /**
     * @return HasMany<DatabaseFlow, $this>
     */
    public function flows(): HasMany
    {
        return $this->hasMany(DatabaseFlow::class);
    }

    /**
     * @return HasMany<ServerLog, $this>
     */
    public function serverLogs(): HasMany
    {
        return $this->hasMany(ServerLog::class);
    }

    public function ensureIdle(): void
    {
        if ($this->isBusy()) {
            throw $this->busyError();
        }

        if ($this->isMigrating()) {
            throw ValidationException::withMessages(['database' => "{$this->name} is migrating data in, finish or stop the migration first."]);
        }
    }

    public function isMigrating(): bool
    {
        return $this->migrations()->active()->exists();
    }

    public function busyError(): ValidationException
    {
        return ValidationException::withMessages(['database' => "{$this->name} is busy with {$this->operation}, try again when it finishes."]);
    }

    public function markCreating(): void
    {
        $this->update(['status' => DatabaseStatus::Creating]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => DatabaseStatus::Failed]);
    }

    public function failSetup(): void
    {
        if ($this->status === DatabaseStatus::Creating) {
            $this->markFailed();
        }
    }

    public function recordStatus(DatabaseStatus $status): void
    {
        $this->update(['status' => $status]);
    }

    public function hasFailed(): bool
    {
        return $this->status === DatabaseStatus::Failed;
    }

    public function isReady(): bool
    {
        return $this->status === DatabaseStatus::Ready;
    }

    public function isServing(): bool
    {
        return $this->status->isServing();
    }

    public function addNode(string $serverId, NodeKind $kind = NodeKind::Member, ?string $name = null): DatabaseNode
    {
        return $this->nodes()->create(['server_id' => $serverId, 'name' => $name, 'position' => $this->nextNodePosition(), 'kind' => $kind]);
    }

    public function nodeLimitOf(NodeKind $kind): int
    {
        return match ($kind) {
            NodeKind::Member => $this->provider()->maxStandbys() + 1,
            NodeKind::ReadOnly => $this->provider()->maxReadOnlyNodes(),
        };
    }

    public function ensureRoomFor(NodeKind $kind): void
    {
        $limit = $this->nodeLimitOf($kind);

        if ($this->nodes()->where('kind', $kind)->count() < $limit) {
            return;
        }

        throw ValidationException::withMessages(['server_id' => match ($kind) {
            NodeKind::Member => "{$this->name} already has a primary and ".($limit - 1).' standby nodes, the most it can have.',
            NodeKind::ReadOnly => "{$this->name} already has {$limit} read-only nodes, the most it can have.",
        }]);
    }

    public function nextNodePosition(): int
    {
        $highest = $this->nodes()->reorder()->max('position');

        return is_int($highest) ? $highest + 1 : 0;
    }

    public function isReachedByDefaultUserAt(string $host, string $username): bool
    {
        return $username === $this->settings->defaultUsername() && in_array(strtolower($host), $this->certificateNames(), true);
    }

    public function hasUser(string $name): bool
    {
        return $this->users()->where('name', $name)->exists();
    }

    public function hasSchema(string $name): bool
    {
        return $this->schemas()->where('name', $name)->exists();
    }

    /**
     * @return list<string>
     */
    public function passwordEncryptions(): array
    {
        return $this->engine_capabilities->passwordEncryptions ?? [];
    }

    public function passwordEncryptionFor(?string $chosen): ?string
    {
        $default = $this->engine_capabilities?->defaultPasswordEncryption;

        if ($default === null) {
            return null;
        }

        return $chosen ?? $default;
    }

    public function managesAccounts(): bool
    {
        return $this->engine_capabilities?->offers('user create') === true;
    }

    public function ensureManagesAccounts(): void
    {
        if ($this->managesAccounts()) {
            return;
        }

        throw ValidationException::withMessages(['database' => "{$this->engine()->label} databases have no users or databases to manage."]);
    }

    /**
     * @return list<Grant>
     */
    public function writeGrants(): array
    {
        return array_map(fn (string $schema): Grant => new Grant($schema, GrantAccess::Write), $this->schemaNames());
    }

    public function recordDefaultAccounts(): void
    {
        if ($this->settings->defaultUsername() === '') {
            return;
        }

        $this->users()->create(['name' => $this->settings->defaultUsername(), 'password' => $this->settings->defaultPassword()]);
        $this->schemas()->create(['name' => $this->settings->defaultSchema()]);
    }

    public function copyAccountsFrom(self $source): void
    {
        $this->users()->createMany($source->users()->get()->map(fn (DatabaseUser $user): array => $user->only(['name', 'password', 'password_encryption'])));
        $this->schemas()->createMany($source->schemas()->get()->map(fn (Schema $schema): array => $schema->only(['name'])));
    }

    public function forgetAccountsMissingFrom(ServerAccounts $accounts): void
    {
        $this->users()->whereNotIn('name', [...$accounts->users, $this->settings->defaultUsername()])->delete();
        $this->schemas()->whereNotIn('name', [...$accounts->databases, $this->settings->defaultSchema()])->delete();
    }

    public function startMigration(DatabaseNode $host, MigrationSource $source, MigrationMode $mode): DatabaseMigration
    {
        return $this->migrations()->create([
            'database_node_id' => $host->id,
            'mode' => $mode,
            'status' => MigrationStatus::Copying,
            'source' => $source,
            'started_at' => now(),
        ]);
    }

    public function moveMaintenanceWindowTo(MaintenanceWindow $window): void
    {
        $this->update(['maintenance_day' => $window->day, 'maintenance_hour' => $window->hour]);
    }

    public function markRetuned(): void
    {
        $this->update(['retune_needed_at' => null]);
    }

    /**
     * @param  array<string, string>  $config
     * @return list<string>
     */
    public function updateConfig(array $config): array
    {
        $current = $this->settings->config;
        $this->update(['settings' => $this->settings->withConfig($config)]);

        return array_values(array_unique(array_merge(array_keys(array_diff_assoc($config, $current)), array_keys(array_diff_assoc($current, $config)))));
    }

    /**
     * @param  list<string>  $keys
     */
    public function configChangeNeedsRestart(array $keys): bool
    {
        return collect($this->provider()->fields())->contains(fn (ConfigField $field): bool => $field->needsRestart() && in_array($field->key, $keys, true));
    }

    public function updatePersistence(Persistence $persistence): void
    {
        $this->update(['settings' => $this->settings->withPersistence($persistence)]);
    }

    public function seed(): DatabaseNode
    {
        return $this->nodes()->firstOrFail();
    }

    public function primary(): ?DatabaseNode
    {
        return $this->nodes()->primary()->first();
    }

    public function primaryOrSeed(): DatabaseNode
    {
        return $this->primary() ?? $this->seed();
    }

    public function primaryIp(): string
    {
        return $this->primaryOrSeed()->privateIp();
    }

    public function primaryServer(): Server
    {
        return $this->primaryOrSeed()->server;
    }

    public function container(): string
    {
        return $this->provider()->container();
    }

    public function image(): EngineImage
    {
        return new EngineImage($this->image_repository, $this->image_digest, $this->image_release);
    }

    public function availableImage(): ?EngineImage
    {
        if ($this->available_image_repository === null || $this->available_image_digest === null || $this->available_image_release === null) {
            return null;
        }

        return new EngineImage($this->available_image_repository, $this->available_image_digest, $this->available_image_release);
    }

    public function recordAvailableImage(?EngineImage $image): void
    {
        $this->update($image?->toAvailableRow() ?? EngineImage::noneAvailableRow());
    }

    public function recordInstalledImage(EngineImage $image): void
    {
        $this->update($image->toInstalledRow());
    }

    public function availableUpdateRefreshesImage(): bool
    {
        return $this->availableImage()?->refreshes($this->image()) ?? false;
    }

    /**
     * @return Collection<int, DatabaseNode>
     */
    public function reachableMembers(): Collection
    {
        return $this->membersWithServers()
            ->filter(fn (DatabaseNode $node): bool => $node->role->isReachable())
            ->sortByDesc(fn (DatabaseNode $node): bool => $node->isPrimary())
            ->values();
    }

    public function firewallTable(): string
    {
        return 'databasify_database_'.str_replace('-', '', $this->id);
    }

    /**
     * @return array<int, string>
     */
    public function trustedCidrs(): array
    {
        return array_values(array_filter($this->trustedSources()->pluck('cidr')->all(), is_string(...)));
    }

    /**
     * @return array<int, string>
     */
    public function userNames(): array
    {
        return array_values(array_filter($this->users()->pluck('name')->all(), is_string(...)));
    }

    /**
     * @return list<string>
     */
    public function schemaNames(): array
    {
        return array_values(array_filter($this->schemas()->pluck('name')->all(), is_string(...)));
    }

    public function matchModeToMembers(): void
    {
        $this->update(['mode' => DatabaseMode::forMembers($this->memberCount())]);
    }

    public function endpoints(): DatabaseEndpoints
    {
        return $this->endpointsAmong($this->nodesWithServers());
    }

    public function endpointsAmong(DatabaseNodeCollection $nodes): DatabaseEndpoints
    {
        $port = $this->provider()->ports()->client;
        $members = $nodes->members();
        $entry = $members->entry() ?? $this->seed();
        $hostnames = $this->hostnames();
        $primary = $entry->endpointPair($hostnames?->primary, $port);

        return new DatabaseEndpoints($primary->public, $primary->vpc, $members->standbyBesides($entry)?->endpointPair($hostnames?->replica, $port));
    }

    public function canFailover(): bool
    {
        return $this->provider()->canFailover($this->nodes()->get());
    }

    public function dataKey(): string
    {
        return "{$this->name}-{$this->created_at?->getTimestamp()}";
    }

    public function nodeKeyOf(DatabaseNode $node): string
    {
        return "{$this->dataKey()}-{$node->id}";
    }

    public function recoveryOrFail(): PointInTimeRecovery
    {
        $this->ensureArchivesForRecovery();

        return $this->pointInTimeRecovery
            ?? throw ValidationException::withMessages(['recovery' => "{$this->name} has no point-in-time recovery, turn it on first."]);
    }

    public function enableRecovery(RecoverySettings $settings): PointInTimeRecovery
    {
        $this->ensureArchivesForRecovery();

        if ($this->pointInTimeRecovery !== null) {
            throw ValidationException::withMessages(['recovery' => "{$this->name} already has point-in-time recovery."]);
        }

        $provider = StorageProvider::query()->findOrFail($settings->storageProviderId);
        $recovery = $this->pointInTimeRecovery()->create(array_merge([
            'storage_provider_id' => $provider->id,
            'path' => $provider->bucket()->under("{$this->engine}/{$this->dataKey()}/".now()->format('YmdHis')),
        ], $settings->schedule->toRow()));
        $this->unsetRelation('pointInTimeRecovery');

        return $recovery;
    }

    public function disableRecovery(): StoredArchive
    {
        $recovery = $this->recoveryOrFail();
        $archive = $recovery->storedArchive();
        $recovery->delete();
        $this->unsetRelation('pointInTimeRecovery');

        return $archive;
    }

    /**
     * @return list<NodeFile>
     */
    public function archiveFiles(): array
    {
        return $this->pointInTimeRecovery === null ? [] : $this->provider()->archive()?->files($this->pointInTimeRecovery) ?? [];
    }

    private function ensureArchivesForRecovery(): void
    {
        if (! $this->archivesForRecovery()) {
            throw ValidationException::withMessages(['recovery' => $this->missingRecoveryMessage()]);
        }
    }

    public function archivesForRecovery(): bool
    {
        return $this->provider()->archive() !== null && ($this->versionLine()->archives ?? true);
    }

    public function missingRecoveryMessage(): string
    {
        return $this->recoveryUnavailableMessage() ?? "{$this->provider()->label()} has no point-in-time recovery.";
    }

    public function recoveryUnavailableMessage(): ?string
    {
        if ($this->provider()->archive() === null || $this->archivesForRecovery()) {
            return null;
        }

        return "Point-in-time recovery is not available for {$this->versionLabel()} at this moment.";
    }

    public function versionLabel(): string
    {
        return $this->versionLine()->label ?? "{$this->provider()->label()} {$this->version_line}";
    }

    private function versionLine(): ?VersionLine
    {
        return $this->provider()->versions()->firstWhere('value', $this->version_line);
    }

    /**
     * @return array<string, string>
     */
    public function tunedConfig(): array
    {
        $smallest = $this->nodes->sortBy(fn (DatabaseNode $node): int => $node->server->memory_mb ?? 0)->first();

        return $smallest === null ? [] : $this->provider()->tunedSettings(NodeResources::of($smallest->server));
    }

    /**
     * @return list<string>
     */
    public function processNames(): array
    {
        return $this->log_units ?? [];
    }

    public static function providerFor(string $engine): DatabaseDriver
    {
        return (new self(['engine' => $engine]))->provider();
    }

    /**
     * @return list<string>
     */
    public static function enginesLabelledLike(string $term): array
    {
        return array_values(EngineCatalog::all()
            ->filter(fn (Engine $engine): bool => str_contains(strtolower($engine->label), strtolower($term)))
            ->keys()
            ->all());
    }

    /**
     * @return list<string>
     */
    public static function engines(): array
    {
        return array_values(EngineCatalog::all()->keys()->all());
    }

    /**
     * @return array<string, DatabaseDriver>
     */
    public static function providers(): array
    {
        return array_combine(self::engines(), array_map(self::providerFor(...), self::engines()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_values(EngineCatalog::all()
            ->sortBy(fn (Engine $engine): string => $engine->category)
            ->map(fn (Engine $engine): array => $engine->toArray())
            ->all());
    }

    public function engine(): Engine
    {
        return EngineCatalog::find($this->engine);
    }

    public function capabilities(): EngineCapabilities
    {
        return $this->engine_capabilities ?? throw new LogicException("{$this->name} has not read its engine capabilities yet.");
    }

    /**
     * @return list<ConfigField>
     */
    public function configFields(): array
    {
        return array_map(ConfigField::fromEngine(...), $this->config_fields);
    }

    public function provider(): DatabaseDriver
    {
        $handler = config("database-provider.providers.{$this->engine}.handler");

        if (! is_string($handler) || ! is_subclass_of($handler, AbstractDatabaseDriver::class)) {
            throw UnknownProvider::named($this->engine);
        }

        return new $handler($this);
    }

    public function engineAccounts(): EngineAccounts
    {
        $engine = $this->provider();

        return $engine->accounts() ?? throw MissingCapability::rejectionOf($engine, 'accounts');
    }

    public function engineArchive(): EngineArchive
    {
        $engine = $this->provider();

        return $engine->archive() ?? throw MissingCapability::of($engine, 'archive');
    }

    public function engineMigration(): EngineMigration
    {
        $engine = $this->provider();

        return $engine->migration() ?? throw MissingCapability::of($engine, 'migration');
    }

    private function stopKey(): string
    {
        return "databases.{$this->id}.stop";
    }

    private function forgetStopFlags(): void
    {
        Cache::forget($this->stopKey());
        Cache::forget($this->cleanupKey());
    }

    private function cleanupKey(): string
    {
        return "databases.{$this->id}.cleanup";
    }

    protected function casts(): array
    {
        return [
            'engine_capabilities' => AsEngineCapabilities::class,
            'config_fields' => AsEngineFields::class,
            'log_units' => 'array',
            'mode' => DatabaseMode::class,
            'status' => DatabaseStatus::class,
            'settings' => AsDatabaseSettings::class,
            'maintenance_day' => 'integer',
            'maintenance_hour' => 'integer',
            'retune_needed_at' => 'datetime',
            'operation_started_at' => 'datetime',
        ];
    }
}
