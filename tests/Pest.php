<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Contracts\Acme\CertificateAuthority;
use App\Contracts\Engines\EngineScripts;
use App\Contracts\Images\ImageRegistry;
use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\NewDatabase;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Updates\Release;
use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\Payload;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use App\Models\NodeMetricSample;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\Server;
use App\Models\Setting;
use App\Models\StorageProvider;
use App\Support\Projects\ProjectContext;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCertificateAuthority;
use Tests\Support\FakeImageRegistry;
use Tests\Support\FakeScriptRunner;
use Tests\Support\FakeServerConnection;
use Tests\Support\LocalScriptRunner;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        fakeCertificateAuthority();
        fakeSsh();
        fakeImageRegistry();
        config()->set('inertia.ssr.enabled', false);
    })
    ->in('Feature');

function projectUrl(string $path): string
{
    return '/'.ProjectFactory::defaultProject()->id.$path;
}

function inProject(): Project
{
    $project = ProjectFactory::defaultProject();
    resolve(ProjectContext::class)->initialize($project);

    return $project;
}

/**
 * @template TResult
 *
 * @param  Closure(): TResult  $callback
 * @return TResult
 */
function inProjectDuring(Closure $callback): mixed
{
    $context = resolve(ProjectContext::class);
    $wasInitialized = $context->isInitialized();
    inProject();

    try {
        return $callback();
    } finally {
        if (! $wasInitialized) {
            $context->terminate();
        }
    }
}

function configureFromFixture(array $request): NodeConfigure
{
    $archive = $request['archive'] === null ? null : new ArchiveSettings(
        new Storage(...array_intersect_key($request['archive']['storage'], array_flip(['endpoint', 'bucket', 'region', 'accessKey', 'secretKey', 'pathStyle', 'path']))),
        $request['archive']['retentionDays'],
        $request['archive']['backupHour'],
    );

    return new NodeConfigure(
        $request['clustered'],
        $request['readOnly'],
        $request['ip'],
        $request['databaseId'],
        array_map(fn (array $member): Member => new Member(...$member), $request['members']),
        $request['settings'] ?? [],
        new Secrets(...$request['secrets']),
        $archive,
        $request['primary'],
    );
}

function nodeConfigFromFixture(array $request): NodeConfig
{
    $archive = $request['archive'] === null ? null : new ArchiveSettings(
        new Storage(...array_intersect_key($request['archive']['storage'], array_flip(['endpoint', 'bucket', 'region', 'accessKey', 'secretKey', 'pathStyle', 'path']))),
        $request['archive']['retentionDays'],
        $request['archive']['backupHour'],
    );

    return new NodeConfig(
        $request['clustered'],
        $request['readOnly'],
        $request['ip'],
        $request['databaseId'],
        array_map(fn (array $member): Member => new Member(...$member), $request['members']),
        $request['settings'] ?? [],
        new Secrets(...$request['secrets']),
        $archive,
        $request['primary'],
    );
}

function respondToFileGet(FakeServerConnection $ssh, string $path, ?string $content): FakeServerConnection
{
    return $ssh->respondWhen(
        fn (string $server, string $script): bool => str_contains($script, '| databasify file get ') && json_decode((string) base64_decode((string) preg_replace("/.*printf '%s' '([^']+)'.*/s", '$1', $script)), true)['path'] === $path,
        json_encode(['contentBase64' => base64_encode($content ?? ''), 'exists' => $content !== null], JSON_THROW_ON_ERROR),
    );
}

function goFixture(string $name, string $engine = 'postgres'): mixed
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$engine}/{$name}")), true);
}

function fakeScripts(): FakeScriptRunner
{
    $fake = resolve(FakeScriptRunner::class);
    app()->instance(EngineScripts::class, $fake);

    return $fake;
}

/**
 * @return array{string, string}
 */
function selfSignedPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'node'], $key), null, $key, 1);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $keyPem);

    return [$pem, $keyPem];
}

function localScripts(): LocalScriptRunner
{
    $local = resolve(LocalScriptRunner::class);
    app()->instance(EngineScripts::class, $local);

    return $local;
}

function fakeSsh(): FakeServerConnection
{
    $fake = new FakeServerConnection;
    app()->instance(ServerConnection::class, $fake);

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function engineCapabilitiesPayload(string $engine): array
{
    $ports = match ($engine) {
        'postgres' => ['client' => 25060, 'pool' => 25061, 'peers' => [8008, 2379, 2380]],
        'mysql' => ['client' => 25060, 'pool' => null, 'peers' => [33061]],
        'mariadb' => ['client' => 25060, 'pool' => null, 'peers' => [4444, 4567, 4568]],
        'valkey', 'redis' => ['client' => 25061, 'pool' => null, 'peers' => [6379, 26379]],
        default => ['client' => 25060, 'pool' => null, 'peers' => []],
    };

    [$users, $databases] = match ($engine) {
        'postgres' => [['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'], ['postgres', 'template0', 'template1']],
        'mysql' => [['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'databasify_repl', 'databasify_agent'], ['mysql', 'sys', 'performance_schema', 'information_schema']],
        'mariadb' => [['root', 'mysql', 'mariadb.sys', 'databasify_sst', 'databasify_repl', 'databasify_agent'], ['mysql', 'sys', 'performance_schema', 'information_schema', 'databasify']],
        'mongodb' => [['root', 'databasify_pbm', '__system'], ['admin', 'local', 'config']],
        default => [[], []],
    };

    $commands = [...(in_array($engine, ['valkey', 'redis'], true) ? ['node status'] : ['account list', 'database create', 'database drop', 'node status', 'user create', 'user drop', 'user grant', 'user set-password']), 'runtime run'];
    [$encryptions, $default] = match ($engine) {
        'mysql' => [['caching_sha2_password', 'mysql_native_password'], 'caching_sha2_password'],
        'mariadb' => [['mysql_native_password', 'ed25519', 'parsec'], 'mysql_native_password'],
        'mongodb' => [['SCRAM-SHA-256', 'SCRAM-SHA-1'], 'SCRAM-SHA-256'],
        default => [[], null],
    };

    return ['commands' => [...$commands, ...(in_array($engine, ['valkey', 'redis'], true) ? [] : ['archive status', 'restore start'])], 'passwordEncryptions' => $encryptions, 'defaultPasswordEncryption' => $default, 'reservedUsers' => $users, 'reservedDatabases' => $databases, 'ports' => $ports];
}

/**
 * @return array<string, mixed>
 */
function engineFieldPayload(): array
{
    return ['key' => 'max_connections', 'label' => 'Max connections', 'description' => 'Connections at once.', 'kind' => 'integer', 'default' => '100', 'options' => [], 'unit' => null, 'min' => 10, 'max' => 10000, 'pattern' => null, 'restart' => true, 'tuned' => false];
}

function withEngineDetails(Database $database): Database
{
    $database->update([
        'engine_capabilities' => EngineCapabilities::fromPayload(new Payload(engineCapabilitiesPayload($database->engine))),
        'config_fields' => [EngineField::fromPayload(new Payload(engineFieldPayload()))],
        'log_units' => [$database->engine, "databasify-{$database->engine}"],
    ]);

    return $database;
}

function fakeEngine(?FakeServerConnection $ssh = null): FakeServerConnection
{
    $operation = fn (string $state): array => ['key' => 'k', 'kind' => 'long', 'state' => $state, 'phase' => '', 'progress' => null, 'startedAt' => '2026-11-10T00:00:00Z', 'finishedAt' => null, 'error' => null];

    return ($ssh ?? fakeSsh())
        ->respondToEngine('host deploy', ['engine' => 'engine', 'container' => 'databasify-engine', 'created' => true, 'replaced' => false])
        ->respondToEngine('host remove', [])
        ->respondToEngine('tls install', [])
        ->respondToEngine('node configure', ['changed' => true])
        ->respondToEngine('node start', [])
        ->respondToEngine('node bootstrap', [])
        ->respondToEngine('node ready', $operation('running'))
        ->respondToEngine('cluster enable', $operation('running'))
        ->respondToEngine('cluster rejoin', $operation('running'))
        ->respondToEngine('cluster membership', [])
        ->respondToEngine('cluster disable', ['droppedSlots' => 0])
        ->respondToEngine('cluster failover', [])
        ->respondToEngine('member forget', [])
        ->respondToEngine('node reset', [])
        ->respondToEngine('user create', ['created' => true])
        ->respondToEngine('user set-password', [])
        ->respondToEngine('user drop', ['dropped' => true])
        ->respondToEngine('user grant', [])
        ->respondToEngine('database create', ['created' => true])
        ->respondToEngine('database drop', ['dropped' => true])
        ->respondToEngine('pool list', ['pools' => []])
        ->respondToEngine('pool create', [])
        ->respondToEngine('pool update', [])
        ->respondToEngine('pool delete', [])
        ->respondToEngine('account list', ['users' => ['databasify'], 'databases' => ['defaultdb'], 'grants' => (object) []])
        ->respondToEngine('migration check', ['reachable' => true, 'error' => null, 'version' => '8.4.3', 'walLevel' => null, 'canReplicate' => true, 'sizeMb' => 12, 'databases' => ['shop'], 'unreadable' => [], 'unowned' => [], 'occupied' => [], 'keys' => null, 'warnings' => [], 'problems' => []])
        ->respondToEngine('migration start', [])
        ->respondToEngine('migration progress', ['copy' => 'running', 'log' => 'copying shop', 'subscribedTables' => 0, 'readyTables' => 0, 'lagSeconds' => null, 'lagUnknown' => true])
        ->respondToEngine('migration stop', [])
        ->respondToEngine('logs read', ['units' => ['engine-server'], 'lines' => ['ready']])
        ->respondToEngine('node metrics', ['connections' => 3, 'queries' => 120, 'dataMb' => 40, 'keys' => null, 'hits' => null, 'misses' => null, 'evicted' => null])
        ->respondToEngine('operation status', $operation('succeeded'))
        ->respondToEngine('node status', ['role' => 'primary', 'version' => '1.0'])
        ->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '0.2.0'])
        ->respondToEngine('engine capabilities', engineCapabilitiesPayload('postgres'))
        ->respondToEngine('config fields', ['fields' => [engineFieldPayload()]]);
}

function answerNodeStatus(FakeServerConnection $ssh, Server $server, ?string $role, ?string $version = null): FakeServerConnection
{
    $reply = $role === null ? ['error' => ['code' => 'unavailable', 'message' => 'unreachable', 'details' => (object) []]] : ['role' => $role, 'version' => $version];

    return $ssh->respondFirstWhen(
        fn (string $name, string $script): bool => $name === $server->name && str_contains($script, '| databasify node status '),
        json_encode($reply, JSON_THROW_ON_ERROR),
        $role === null ? 1 : 0,
    );
}

/**
 * @return list<string>
 */
function engineCommandsIn(FakeServerConnection $ssh): array
{
    return array_values(collect($ssh->runs)
        ->map(fn (array $run): ?string => preg_match('/\\| databasify ([a-z-]+ [a-z-]+) 2>/', $run['script'], $match) === 1 ? "{$run['server']}: {$match[1]}" : null)
        ->filter()
        ->all());
}

function advanceFlows(int $ticks = 20): void
{
    foreach (range(1, $ticks) as $tick) {
        DatabaseFlow::query()->running()->get()->each(fn (DatabaseFlow $flow) => runJob(new AdvanceDatabaseFlow($flow)));
    }
}

function runJob(ShouldQueue $job): void
{
    resolve(Pipeline::class)->send($job)->through($job->middleware())->then(fn (ShouldQueue $job) => app()->call([$job, 'handle']));
}

function fakeImageRegistry(): FakeImageRegistry
{
    $fake = FakeImageRegistry::withEveryLine();
    app()->instance(ImageRegistry::class, $fake);

    return $fake;
}

function imageRegistry(): FakeImageRegistry
{
    $registry = resolve(ImageRegistry::class);

    return $registry instanceof FakeImageRegistry ? $registry : fakeImageRegistry();
}

function bashErrorsIn(string $script): string
{
    $result = Process::input($script)->run(['bash', '-n']);

    return $result->successful() ? '' : $result->errorOutput();
}

/**
 * @param  array<string, string>  $env
 */
function installerScript(string $snippet, array $env = []): ProcessResult
{
    $script = "set -euo pipefail\nSOURCE=\$DATABASIFY_SOURCE\n. \"\$SOURCE/lib.sh\"\n{$snippet}";

    return Process::input($script)
        ->env($env + ['NO_COLOR' => '1', 'DATABASIFY_SOURCE' => base_path('scripts')])
        ->run(['bash']);
}

function temporaryPath(): string
{
    $path = sys_get_temp_dir().'/databasify-'.bin2hex(random_bytes(4));

    register_shutdown_function(function () use ($path): void {
        is_dir($path) ? (new Filesystem)->deleteDirectory($path) : @unlink($path);
    });

    return $path;
}

function temporaryDirectory(): string
{
    $path = temporaryPath();
    mkdir($path);

    return $path;
}

function temporaryFile(string $contents = ''): string
{
    $path = temporaryPath();
    file_put_contents($path, $contents);

    return $path;
}

function fakeHost(int $cpus, int $memoryMb): string
{
    $root = temporaryDirectory();
    mkdir("$root/proc");
    file_put_contents("$root/proc/cpuinfo", collect(range(0, $cpus - 1))->map(fn (int $cpu): string => "processor\t: $cpu\n")->implode("\n"));
    file_put_contents("$root/proc/meminfo", 'MemTotal:       '.($memoryMb * 1024)." kB\n");

    return $root;
}

function filledSecrets(): string
{
    return "APP_KEY=base64:c3RvcmVkLWtleS1zdG9yZWQta2V5LXN0b3JlZC1rZXk=\nREVERB_APP_ID=a1b2c3d4\nREVERB_APP_KEY=0123456789abcdef0123456789abcdef\nREVERB_APP_SECRET=fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210\n";
}

function dockerWithStorageVolume(): string
{
    return 'docker() { [ "$*" = "volume inspect databasify_storage" ] || [ "$1" = info ]; }';
}

function dockerWithoutStorageVolume(): string
{
    return 'docker() { [ "$1" = info ]; }';
}

function workersFor2CpusAnd4Gb(): string
{
    return "HORIZON_DEFAULT_MAX_PROCESSES=6\nHORIZON_SSH_MAX_PROCESSES=18\n";
}

function javascriptErrorsIn(string $script): string
{
    $result = Process::input($script)->run(['node', '--check']);

    return $result->successful() ? '' : $result->errorOutput();
}

function newDatabase(string $engine, int $servers, DatabaseMode $mode = DatabaseMode::HighAvailability, string $name = 'main', ?string $versionLine = null): Database
{
    $ids = Server::factory()->ready()->count($servers)->create()->pluck('id')->all();
    $queue = Queue::getFacadeRoot();
    Queue::fake([AdvanceDatabaseFlow::class]);

    $database = inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase($name, $engine, $ids[0], array_slice($ids, 1), versionLine: $versionLine)));
    Queue::swap($queue);
    withEngineDetails($database);
    $database->update(['mode' => $mode]);

    return $database;
}

function withImage(string $engine, string $release, ?string $digest = null, ?string $sdk = '0.2.0'): void
{
    imageRegistry()->publish($engine, $release, $digest, $sdk);
}

function idleDatabase(string $engine, int $servers, DatabaseMode $mode = DatabaseMode::HighAvailability, string $name = 'main', ?string $versionLine = null): Database
{
    $database = newDatabase($engine, $servers, $mode, $name, $versionLine);
    $database->flows()->update(['state' => FlowState::Succeeded]);
    $database->finishOperation();

    return $database;
}

function engineNode(): DatabaseNode
{
    return idleDatabase('postgres', 1)->nodes()->sole();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function withReadOnlyNode(Database $database, array $attributes = []): DatabaseNode
{
    return $database->nodes()->create([
        'server_id' => Server::factory()->ready()->create()->id,
        'position' => $database->nodes()->count(),
        'kind' => NodeKind::ReadOnly,
        ...$attributes,
    ]);
}

function markRoles(Database $database, NodeRole ...$roles): void
{
    $database->nodes()->get()->each(fn (DatabaseNode $node, int $index) => $node->update(['role' => $roles[$index] ?? NodeRole::Replica]));
}

function migratingDatabase(string $engine): Database
{
    $database = idleDatabase($engine, 1, DatabaseMode::Single, $engine);
    $database->seed()->update(['role' => NodeRole::Primary]);

    return $database;
}

function migrationOf(Database $database, MigrationMode $mode, MigrationStatus $status = MigrationStatus::Copying): DatabaseMigration
{
    return $database->migrations()->create([
        'database_node_id' => $database->seed()->id,
        'mode' => $mode,
        'status' => $status,
        'source' => new MigrationSource('db.example.com', 3306, 'migrator', "pa'ss\\word"),
        'started_at' => now(),
    ]);
}

function sampleCpu(DatabaseNode $node, float ...$values): void
{
    foreach ($values as $index => $value) {
        NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now()->subMinutes(count($values) - $index), 'cpu_percent' => $value, 'memory_percent' => 40, 'disk_percent' => 30]);
    }
}

/**
 * @param  list<array{id: string, content: string, comment?: string}>  $records
 */
function fakeCloudflare(array $records = [['id' => 'old', 'content' => '203.0.113.9', 'comment' => 'databasify']]): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => $records]),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['success' => true, 'result' => []]),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function withRecovery(Database $database, array $attributes = [], ?StorageProvider $provider = null, bool $restorable = false): Database
{
    PointInTimeRecovery::factory()
        ->for($database)
        ->for($provider ?? StorageProvider::factory()->create())
        ->when($restorable, fn ($factory) => $factory->restorable())
        ->create(['path' => "{$database->engine}/{$database->dataKey()}", ...$attributes]);

    return $database->fresh() ?? $database;
}

function fakeBucket(string $readBack = 'databasify'): void
{
    Http::fake(fn (Request $request) => Http::response($request->method() === 'GET' ? $readBack : ''));
}

function fakeCertificateAuthority(): FakeCertificateAuthority
{
    $fake = new FakeCertificateAuthority;
    app()->instance(CertificateAuthority::class, $fake);

    return $fake;
}

function withZoneCertificate(DnsZone $zone, int $days = 90): DnsZone
{
    $zone->update(['certificate' => issuedPem([$zone->wildcardName()], $days), 'certificate_key' => 'zone-key']);

    return $zone;
}

function withZone(Database $database, string $name = 'example.com'): Database
{
    $database->update(['dns_zone_id' => DnsZone::factory()->create(['name' => $name])->id]);

    return $database->fresh() ?? $database;
}

/**
 * @param  list<string>  $names
 */
function issuedPem(array $names, int $days = 365, bool $rsa = false): string
{
    $config = (string) tempnam(sys_get_temp_dir(), 'san');
    $alternatives = implode(',', array_map(fn (string $name): string => (filter_var($name, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:').$name, $names));
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName={$alternatives}\n");
    $key = openssl_pkey_new($rsa ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $request = openssl_csr_new(['commonName' => 'test'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($request, null, $key, $days, ['config' => $config, 'x509_extensions' => 'san', 'digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $pem);
    (new Filesystem)->delete($config);

    return $pem;
}

function withCurrentCertificates(Database $database): Database
{
    resolve(IssueNodeCertificates::class)->handle($database);

    return $database->fresh() ?? $database;
}

function storeRelease(string $version, string $notes): void
{
    $release = new Release($version, CarbonImmutable::parse('2026-11-20T08:00:00Z'), "https://github.com/databasifylabs/databasify/releases/tag/{$version}", $notes);

    Setting::query()->updateOrCreate(['name' => 'latest_release'], ['value' => $release->toJson()]);
}

function installedUpdater(): string
{
    $path = temporaryDirectory();
    mkdir("{$path}/request");
    touch("{$path}/installed");
    config()->set('databasify.updates.path', $path);

    return $path;
}

function updateStatusJson(string $state, bool $rolledBack = false, string $failedStep = ''): string
{
    $steps = collect(['Refreshing release files', 'Pulling images', 'Waiting until healthy'])
        ->map(fn (string $title): array => ['title' => $title, 'state' => $title === $failedStep ? 'failed' : 'done'])
        ->all();

    return (string) json_encode(['state' => $state, 'from' => '0.1.0', 'to' => '0.2.0', 'started_at' => '2026-11-20T03:14:02Z', 'finished_at' => $state === 'running' ? null : '2026-11-20T03:16:06Z', 'rolled_back' => $rolledBack, 'message' => $rolledBack ? 'Rolled back to 0.1.0' : '', 'steps' => $steps]);
}

function runningStatusJson(): string
{
    return updateStatusJson('running');
}
