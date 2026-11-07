<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Contracts\Acme\CertificateAuthority;
use App\Contracts\Images\ImageRegistry;
use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\NewDatabase;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use App\Models\NodeMetricSample;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use App\Support\Projects\ProjectContext;
use Database\Factories\ProjectFactory;
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
use Tests\Support\FakeServerConnection;
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

function fakeSsh(): FakeServerConnection
{
    $fake = new FakeServerConnection;
    app()->instance(ServerConnection::class, $fake);

    return $fake;
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

function javascriptErrorsIn(string $script): string
{
    $result = Process::input($script)->run(['node', '--check']);

    return $result->successful() ? '' : $result->errorOutput();
}

function certificateOutput(string ...$files): string
{
    return collect($files)->map(fn (string $file) => "CERT {$file} ".base64_encode("{$file}-content"))->implode("\n")."\n";
}

/**
 * @param  array<string, mixed>  $data
 */
function engineScript(string $engine, array $data = []): array
{
    $driver = Database::providerFor($engine);

    return [...$driver->scriptVariables(), 'directory' => $driver->directory(), 'clientPort' => $driver->ports()->client, ...$data];
}

function renderedFile(Database $database, string $suffix, ?DatabaseNode $node = null): string
{
    $node ??= $database->seed();
    $file = collect($database->provider()->configFiles($node))
        ->first(fn (NodeFile $file): bool => str_ends_with($file->path, $suffix));

    return $file?->content ?? '';
}

/**
 * @param  array<string, mixed>  $data
 */
function accountSql(string $engine, string $statement, array $data): string
{
    return view(Database::providerFor($engine)->scriptView("accounts.{$statement}"), ['databases' => [], 'users' => [], 'encryption' => null, ...$data])->render();
}

function fileWrittenIn(string $script, string $path): string
{
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| put_file '".preg_quote($path, '/')."'/", $script, $match);

    return (string) base64_decode($match[1] ?? '', true);
}

function newDatabase(string $engine, int $servers, DatabaseMode $mode = DatabaseMode::HighAvailability, string $name = 'main', ?string $versionLine = null): Database
{
    Queue::fake([BootstrapDatabaseJob::class]);
    $ids = Server::factory()->ready()->count($servers)->create()->pluck('id')->all();

    $database = inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase($name, $engine, $ids[0], array_slice($ids, 1), versionLine: $versionLine)));
    $database->update(['mode' => $mode]);

    return $database;
}

function withImage(string $engine, string $release, ?string $digest = null): void
{
    imageRegistry()->publish($engine, $release, $digest);
}

function idleDatabase(string $engine, int $servers, DatabaseMode $mode = DatabaseMode::HighAvailability, string $name = 'main', ?string $versionLine = null): Database
{
    $database = newDatabase($engine, $servers, $mode, $name, $versionLine);
    $database->finishOperation();

    return $database;
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

function sqlSentIn(string $script): string
{
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| msql_from/", $script, $match);

    return (string) base64_decode($match[1] ?? '', true);
}

function markRoles(Database $database, NodeRole ...$roles): void
{
    $database->nodes()->get()->each(fn (DatabaseNode $node, int $index) => $node->update(['role' => $roles[$index] ?? NodeRole::Replica]));
}

function mysqlSourceReport(string $logBin = '1', string $grants = 'GRANT SELECT, RELOAD, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `m`@`%`', string $gtidMode = 'ON', string $binlogExpireSeconds = '2592000'): string
{
    return 'REPORT '.json_encode(['reachable' => true, 'version' => '8.4.13', 'log_bin' => $logBin, 'binlog_format' => 'ROW', 'gtid_mode' => $gtidMode, 'binlog_expire_logs_seconds' => $binlogExpireSeconds, 'size_mb' => '12.5', 'grants' => $grants])."\n";
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
    $files = array_values(array_filter($database->provider()->tls()->files, fn (string $name): bool => $name !== 'ca.crt'));
    $rsa = $database->provider()->tls()->rsaKey;
    $own = fn (array $names): array => collect($files)->mapWithKeys(fn (string $name): array => [$name => str_ends_with($name, '.key') ? 'server-key' : issuedPem($names, rsa: $rsa)])->all();
    $settings = $database->settings->withCertificates([...$database->settings->certificates, 'ca.crt' => 'authority-cert', 'ca.key' => 'authority-key', ...$own($database->certificateNames())]);
    $database->nodes()->readOnly()->with(['server', 'database.dnsZone'])->get()
        ->each(function (DatabaseNode $node) use (&$settings, $own): void {
            $settings = $settings->withNodeCertificates($node->id, $own($node->certificateNames()));
        });
    $database->update(['settings' => $settings]);

    return $database->fresh() ?? $database;
}
