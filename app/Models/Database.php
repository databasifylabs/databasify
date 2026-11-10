<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDatabaseSettings;
use App\Casts\AsEngineCapabilities;
use App\Casts\AsEngineFields;
use App\Contracts\Projects\Movable;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\DatabaseHostnames;
use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\IssuedCertificate;
use App\DTOs\Databases\MaintenanceWindow;
use App\DTOs\Databases\ServerAccounts;
use App\DTOs\Databases\ServiceRecords;
use App\DTOs\Databases\VersionLine;
use App\DTOs\Dns\HostPair;
use App\DTOs\Images\WrapperImage;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Recovery\RecoverySettings;
use App\DTOs\Recovery\StoredArchive;
use App\Engines\Data\EngineField;
use App\Engines\Data\Grant;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Engines\EngineSecrets;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\Persistence;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\SyncDatabaseWrapperJob;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Concerns\BelongsToProject;
use App\Models\Concerns\MovesBetweenProjects;
use App\Observers\DatabaseObserver;
use App\Services\Images\WrapperResolver;
use App\Support\Databases\ClientPorts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Lock;
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
 * @property string|null $wrapper_version
 * @property string|null $wrapper_digest
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
#[Fillable(['project_id', 'name', 'engine', 'version_line', 'mode', 'status', 'image_repository', 'image_digest', 'image_release', 'available_image_repository', 'available_image_digest', 'available_image_release', 'wrapper_version', 'wrapper_digest', 'retune_needed_at', 'maintenance_day', 'maintenance_hour', 'settings', 'dns_zone_id', 'operation', 'operation_started_at', 'engine_capabilities', 'config_fields', 'log_units'])]
#[Hidden(['settings'])]
#[ObservedBy(DatabaseObserver::class)]
class Database extends Model implements Movable
{
    use BelongsToProject, HasUuids, MovesBetweenProjects;

    private const int STOP_TTL_SECONDS = 86400;

    private const int DNS_LOCK_SECONDS = 300;

    public const string CERTIFICATE_AUTHORITY = 'ca.crt';

    private const int AUTOMATIC_FAILOVER_MEMBERS = 3;

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
        return $this->servesPublicCertificate() ? null : $this->settings->certificate(self::CERTIFICATE_AUTHORITY);
    }

    public function servesPublicCertificate(): bool
    {
        $fingerprint = $this->publicCertificateZone()?->certificateFingerprint();

        return $fingerprint !== null && $this->nodes()->pluck('certificate_fingerprint')->every(fn (?string $installed): bool => $installed === $fingerprint);
    }

    public function publicCertificateZone(): ?DnsZone
    {
        return $this->engine()->publicCertificate ? $this->dnsZone : null;
    }

    public function allCertificatesCurrent(): bool
    {
        $zone = $this->publicCertificateZone();

        if ($zone !== null) {
            return $zone->hasCurrentCertificate() && $this->servesPublicCertificate();
        }

        return $this->nodesWithServers()->every(fn (DatabaseNode $node): bool => IssuedCertificate::fromPem($this->settings->nodeCertificate($node->id, 'server.crt') ?? '')?->isCurrentForOnly($node->ownCertificateNames()) === true);
    }

    public function forgetNodeCertificates(DatabaseNode $node): void
    {
        $this->update(['settings' => $this->settings->withoutNodeCertificates($node->id)]);
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
        $record = $this->engine()->serviceRecord;

        if ($zone === null || $record === null) {
            return null;
        }

        $host = $this->hostnamesUnder($zone)->primary->public;

        return new ServiceRecords($zone, $host, "{$record->prefix}.{$host}", $record->options, $this->name, $this->clientPort());
    }

    public function dnsLock(): Lock
    {
        return Cache::lock("database-dns:{$this->id}", self::DNS_LOCK_SECONDS);
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
        $this->ensureIdleOnAnyWrapper();
        $pinned = WrapperResolver::cached();

        if ($pinned !== null && $this->runsWrapper($pinned)) {
            return;
        }

        dispatch(new SyncDatabaseWrapperJob($this));

        if ($pinned !== null) {
            throw ValidationException::withMessages(['database' => "{$this->name} is installing databasify {$pinned->version}, try again in a few minutes."]);
        }
    }

    public function ensureIdleOnAnyWrapper(): void
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
            NodeKind::Member => Engine::MAX_STANDBYS + 1,
            NodeKind::ReadOnly => Engine::MAX_READ_ONLY_NODES,
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
        return "databasify-{$this->engine}";
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

    public function runsWrapper(WrapperImage $wrapper): bool
    {
        return $this->wrapper_version === $wrapper->version && $this->wrapper_digest === $wrapper->digest;
    }

    public function recordWrapper(WrapperImage $wrapper): void
    {
        $this->update($wrapper->toRow());
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
        $port = $this->clientPort();
        $members = $nodes->members();
        $entry = $members->entry() ?? $this->seed();
        $hostnames = $this->hostnames();
        $primary = $entry->endpointPair($hostnames?->primary, $port);

        return new DatabaseEndpoints($primary->public, $primary->vpc, $members->standbyBesides($entry)?->endpointPair($hostnames?->replica, $port));
    }

    public function canFailover(): bool
    {
        $voters = $this->engine()->readOnlyNodesVote ? $this->nodes() : $this->nodes()->members();

        return $voters->count() >= self::AUTOMATIC_FAILOVER_MEMBERS;
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

        return $this->recordRecovery($settings);
    }

    public function recordRecovery(RecoverySettings $settings): PointInTimeRecovery
    {
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

    private function ensureArchivesForRecovery(): void
    {
        if (! $this->archivesForRecovery()) {
            throw ValidationException::withMessages(['recovery' => $this->missingRecoveryMessage()]);
        }
    }

    public function archivesForRecovery(): bool
    {
        return $this->offersArchives() && ($this->versionLine()->archives ?? true);
    }

    private function offersArchives(): bool
    {
        return $this->engine_capabilities?->offers('archive status') === true;
    }

    public function missingRecoveryMessage(): string
    {
        return $this->recoveryUnavailableMessage() ?? "{$this->engine()->label} has no point-in-time recovery.";
    }

    public function recoveryUnavailableMessage(): ?string
    {
        if (! $this->offersArchives() || $this->archivesForRecovery()) {
            return null;
        }

        return "Point-in-time recovery is not available for {$this->versionLabel()} at this moment.";
    }

    public function versionLabel(): string
    {
        return $this->versionLine()->label ?? "{$this->engine()->label} {$this->version_line}";
    }

    private function versionLine(): ?VersionLine
    {
        return collect($this->engine()->lines)->firstWhere('value', $this->version_line);
    }

    /**
     * @return list<string>
     */
    public function processNames(): array
    {
        return $this->log_units ?? [];
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

    public function clientPort(): int
    {
        return $this->engine_capabilities->ports->client ?? ClientPorts::CLIENT;
    }

    public function connectionProfile(): ConnectionProfile
    {
        $engine = $this->engine();
        $profile = ($this->servesPublicCertificate() ? $engine->publicConnection : null) ?? $engine->connection;

        if ($this->dnsZone === null || $engine->serviceConnection === null) {
            return $profile;
        }

        return $profile->withService($engine->serviceConnection);
    }

    /**
     * @return array<string, string>
     */
    public function environment(DatabaseEndpoints $endpoints): array
    {
        $engine = $this->engine();
        $host = $endpoints->public->host;
        $port = (string) $endpoints->public->port;

        if ($engine->laravelConnection === null) {
            return [
                'REDIS_CLIENT' => 'phpredis',
                'REDIS_SCHEME' => 'tls',
                'REDIS_HOST' => $host,
                'REDIS_PORT' => $port,
                'REDIS_PASSWORD' => $this->settings->secret(EngineSecrets::ADMIN),
            ];
        }

        return [
            'DB_CONNECTION' => $engine->laravelConnection,
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $this->settings->defaultSchema(),
            'DB_USERNAME' => $this->settings->defaultUsername(),
            'DB_PASSWORD' => $this->settings->defaultPassword(),
            ...$engine->environmentExtra,
        ];
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
