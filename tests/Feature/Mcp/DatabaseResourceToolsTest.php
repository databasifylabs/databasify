<?php

declare(strict_types=1);

use App\DTOs\Migrations\SourceReport;
use App\Engines\Data\EngineField;
use App\Enums\Databases\PasswordEncryption;
use App\Enums\Databases\PoolMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Jobs\Databases\ApplyNodeConnectionPoolsJob;
use App\Jobs\Databases\ApplyNodeTrustedSourcesJob;
use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Jobs\Databases\RunMigrationJob;
use App\Jobs\Databases\StopMigrationJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingDriver;

/**
 * @param  array<string, mixed>  $attributes
 */
function seededMcpDatabase(array $attributes = []): Database
{
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders', ...$attributes]);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();

    return $database;
}

function writeDatabasesKey(): void
{
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());
}

/**
 * @return array<string, mixed>
 */
function mcpMigrationSource(): array
{
    return ['host' => 'old-db.example.com', 'port' => 5432, 'username' => 'migrator', 'password' => 'source-password', 'tls' => true];
}

function mcpMaxConnectionsField(): EngineField
{
    return new EngineField('max_connections', 'Max connections', 'Most clients at once', 'integer', '100', [], null, 10, 1000, null, true, true);
}

describe('database users', function () {
    test('the user list never holds a password', function () {
        $database = Database::factory()->create();
        $database->users()->create(['name' => 'reporting', 'password' => 'never-listed']);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('list_database_users', ['database' => $database->id]);

        expect(array_column(mcpData($response), 'name'))->toBe(['reporting'])
            ->and(json_encode($response))->not->toContain('never-listed');
    });

    test('the credentials tool is hidden from a read-only key', function () {
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $names = mcpToolNames();

        expect($names)->not->toContain('get_database_user_credentials');
    });

    test('a write key sees the password of a user', function () {
        $database = Database::factory()->create();
        $user = $database->users()->create(['name' => 'reporting', 'password' => 'shown-to-writers']);
        writeDatabasesKey();

        $response = mcpCall('get_database_user_credentials', ['database' => $database->id, 'user' => $user->id]);

        expect(mcpData($response)['password'])->toBe('shown-to-writers');
    });

    test('a new user is returned with its generated password', function () {
        $database = seededMcpDatabase();
        writeDatabasesKey();

        $response = mcpCall('create_database_user', ['database' => $database->id, 'name' => 'reporting']);

        $user = $database->users()->where('name', 'reporting')->sole();
        expect(mcpData($response))->toMatchArray(['name' => 'reporting', 'password' => $user->password]);
    });

    test('resetting a password returns the new one', function () {
        $database = seededMcpDatabase();
        $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-password']);
        writeDatabasesKey();

        $response = mcpCall('reset_database_user_password', ['database' => $database->id, 'user' => $user->id]);

        expect(mcpData($response)['password'])->not->toBe('old-password')->toBe($user->refresh()->password);
    });

    test('a mysql user switches its password encryption', function () {
        $database = seededMcpDatabase(['engine' => 'mysql', 'version_line' => '8.4']);
        $user = $database->users()->create(['name' => 'legacy', 'password' => 'secret', 'password_encryption' => 'caching_sha2_password']);
        writeDatabasesKey();

        $response = mcpCall('update_user_password_encryption', ['database' => $database->id, 'user' => $user->id, 'password_encryption' => 'mysql_native_password']);

        expect(mcpData($response)['name'])->toBe('legacy')
            ->and($user->refresh()->password_encryption)->toBe(PasswordEncryption::MysqlNativePassword);
    });

    test('the default user is not deleted', function () {
        $database = seededMcpDatabase();
        $default = $database->users()->where('name', 'databasify')->sole();
        writeDatabasesKey();

        $response = mcpCall('delete_database_user', ['database' => $database->id, 'user' => $default->id]);

        expect(mcpError($response))->toContain("databasify is the default user and can't be deleted.");
        $this->assertModelExists($default);
    });

    test('a user of another database is not found and kept', function () {
        $database = Database::factory()->create();
        $foreign = Database::factory()->create()->users()->create(['name' => 'reporting', 'password' => 'secret']);
        writeDatabasesKey();

        $response = mcpCall('delete_database_user', ['database' => $database->id, 'user' => $foreign->id]);

        expect(mcpError($response))->toContain('No database_user with this id.');
        $this->assertModelExists($foreign);
    });
});

describe('schemas', function () {
    test('a schema is created', function () {
        $database = seededMcpDatabase();
        writeDatabasesKey();

        $response = mcpCall('create_schema', ['database' => $database->id, 'name' => 'analytics']);

        expect(mcpData($response))->toMatchArray(['name' => 'analytics', 'is_default' => false]);
        $this->assertDatabaseHas('schemas', ['database_id' => $database->id, 'name' => 'analytics']);
    });

    test('a reserved schema name is refused', function () {
        $database = seededMcpDatabase();
        writeDatabasesKey();

        $response = mcpCall('create_schema', ['database' => $database->id, 'name' => 'template1']);

        expect(mcpError($response))->toContain('The selected name is invalid.');
        $this->assertDatabaseMissing('schemas', ['database_id' => $database->id, 'name' => 'template1']);
    });

    test('schemas are listed', function () {
        $database = seededMcpDatabase();
        $database->schemas()->create(['name' => 'analytics']);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('list_schemas', ['database' => $database->id]);

        expect(array_column(mcpData($response), 'name'))->toContain('analytics');
    });

    test('a schema is deleted', function () {
        $database = seededMcpDatabase();
        $schema = $database->schemas()->create(['name' => 'analytics']);
        writeDatabasesKey();

        $response = mcpCall('delete_schema', ['database' => $database->id, 'schema' => $schema->id]);

        expect(mcpData($response))->toBe(['deleted' => $schema->id]);
        $this->assertModelMissing($schema);
    });
});

describe('point-in-time recovery', function () {
    test('recovery is turned on with a bucket of the same project', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create();
        $storage = StorageProvider::factory()->for($database->project)->create();
        writeDatabasesKey();

        $response = mcpCall('enable_recovery', ['database' => $database->id, 'storage_provider_id' => $storage->id, 'retention_days' => 14, 'backup_hour' => 2]);

        expect(mcpData($response)['point_in_time_recovery'])->toMatchArray(['storage_provider_id' => $storage->id, 'retention_days' => 14]);
        $this->assertDatabaseHas('point_in_time_recoveries', ['database_id' => $database->id, 'storage_provider_id' => $storage->id, 'retention_days' => 14]);
        Queue::assertPushed(ApplySettingsJob::class);
    });

    test('a bucket of another project is refused', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create();
        $foreign = StorageProvider::factory()->for(Project::factory())->create();
        writeDatabasesKey();

        $response = mcpCall('enable_recovery', ['database' => $database->id, 'storage_provider_id' => $foreign->id, 'retention_days' => 14, 'backup_hour' => 2]);

        expect(mcpError($response))->toContain('The selected storage provider id is invalid.');
        $this->assertDatabaseMissing('point_in_time_recoveries', ['database_id' => $database->id]);
        Queue::assertNotPushed(ApplySettingsJob::class);
    });

    test('the recovery settings are shown', function () {
        $database = Database::factory()->create();
        PointInTimeRecovery::factory()->for($database)->create(['retention_days' => 7]);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('get_recovery', ['database' => $database->id]);

        expect(mcpData($response)['retention_days'])->toBe(7);
    });

    test('a database without recovery reports that it has none', function () {
        $database = Database::factory()->create(['name' => 'orders']);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('get_recovery', ['database' => $database->id]);

        expect(mcpError($response))->toContain('orders has no point-in-time recovery.');
    });

    test('the recovery schedule changes', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create();
        PointInTimeRecovery::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('update_recovery_schedule', ['database' => $database->id, 'retention_days' => 21, 'backup_hour' => 4]);

        expect(mcpData($response)['point_in_time_recovery']['retention_days'])->toBe(21);
        $this->assertDatabaseHas('point_in_time_recoveries', ['database_id' => $database->id, 'retention_days' => 21, 'backup_hour' => 4]);
    });

    test('a backup is taken now', function () {
        Queue::fake([RefreshRecoveryStatusJob::class]);
        $database = Database::factory()->create();
        PointInTimeRecovery::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('run_backup', ['database' => $database->id]);

        expect(mcpData($response)['id'])->toBe($database->id);
        Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job) => $job->backUpNow);
    });

    test('the backup archive is checked', function () {
        Queue::fake([RefreshRecoveryStatusJob::class]);
        $database = Database::factory()->create();
        PointInTimeRecovery::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('check_recovery', ['database' => $database->id]);

        expect(mcpData($response)['id'])->toBe($database->id);
        Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job) => ! $job->backUpNow);
    });

    test('turning recovery off deletes the backups when asked', function () {
        Queue::fake([ApplySettingsJob::class, DeleteRecoveryBackupsJob::class]);
        $database = Database::factory()->create();
        $recovery = PointInTimeRecovery::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('disable_recovery', ['database' => $database->id, 'delete_backups' => true]);

        expect(mcpData($response)['point_in_time_recovery'])->toBeNull();
        $this->assertModelMissing($recovery);
        Queue::assertPushed(DeleteRecoveryBackupsJob::class);
    });

    test('a restore creates a new database from the source', function () {
        $this->freezeTime();
        Queue::fake([CreateDatabaseJob::class]);
        $source = Database::factory()->create(['name' => 'orders']);
        PointInTimeRecovery::factory()->restorable()->for($source)->create();
        $server = Server::factory()->ready()->for($source->project)->create();
        writeDatabasesKey();

        $response = mcpCall('restore_database', ['database' => $source->id, 'name' => 'orders-restored', 'server_id' => $server->id, 'target' => now()->subDay()->utc()->startOfSecond()->toIso8601String()]);

        $restored = mcpData($response);
        expect($restored)->toMatchArray(['name' => 'orders-restored'])
            ->and($restored['restored_from']['database'])->toBe('orders')
            ->and($restored['id'])->not->toBe($source->id);
        $this->assertDatabaseHas('databases', ['name' => 'orders-restored', 'project_id' => $source->project_id]);
        Queue::assertPushed(CreateDatabaseJob::class);
    });
});

describe('migrations', function () {
    test('checking a source reports what the engine found', function () {
        RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop']));
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('check_migration', ['database' => $database->id, ...mcpMigrationSource()]);

        $report = mcpData($response);
        expect($report['reachable'])->toBeTrue()
            ->and($report['facts']['version'])->toBe('16.4');
    });

    test('a migration starts and never returns the source password', function () {
        Queue::fake([RunMigrationJob::class]);
        RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop']));
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->create();
        writeDatabasesKey();

        $response = mcpCall('start_migration', ['database' => $database->id, ...mcpMigrationSource()]);

        expect(mcpData($response)['status'])->toBe('migrating')
            ->and(json_encode($response))->not->toContain('source-password');
        $this->assertDatabaseHas('database_migrations', ['database_id' => $database->id, 'status' => MigrationStatus::Copying->value]);
        Queue::assertPushed(RunMigrationJob::class);
    });

    test('migrations are listed without the source password', function () {
        $migration = DatabaseMigration::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('list_migrations', ['database' => $migration->database_id]);

        expect(array_column(mcpData($response), 'id'))->toBe([$migration->id])
            ->and(json_encode($response))->not->toContain((string) $migration->source->password);
    });

    test('a syncing migration is finished with a cutover', function () {
        Queue::fake([StopMigrationJob::class]);
        $migration = DatabaseMigration::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('finish_migration', ['database' => $migration->database_id, 'migration' => $migration->id]);

        expect(mcpData($response)['id'])->toBe($migration->database_id);
        Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => $job->cutover && ! $job->force);
    });

    test('a migration still copying is not cut over', function () {
        Queue::fake([StopMigrationJob::class]);
        $migration = DatabaseMigration::factory()->status(MigrationStatus::Copying)->create();
        writeDatabasesKey();

        $response = mcpCall('finish_migration', ['database' => $migration->database_id, 'migration' => $migration->id]);

        expect(mcpError($response))->toContain('The initial copy is still running, wait until the migration is syncing.');
        Queue::assertNotPushed(StopMigrationJob::class);
    });

    test('a migration is cancelled with force', function () {
        Queue::fake([StopMigrationJob::class]);
        $migration = DatabaseMigration::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('cancel_migration', ['database' => $migration->database_id, 'migration' => $migration->id, 'force' => true]);

        expect(mcpData($response)['id'])->toBe($migration->database_id);
        Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => ! $job->cutover && $job->force);
    });
});

describe('connection pools', function () {
    test('a connection pool is created and applied', function () {
        Queue::fake([ApplyConnectionPoolsJob::class]);
        $database = Database::factory()->create();
        $database->schemas()->create(['name' => 'shop']);
        writeDatabasesKey();

        $response = mcpCall('create_connection_pool', ['database' => $database->id, 'name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 20]);

        expect(mcpData($response)['status'])->toBe('updating');
        $this->assertDatabaseHas('connection_pools', ['database_id' => $database->id, 'database_node_id' => null, 'name' => 'shop_pool', 'size' => 20]);
        Queue::assertPushed(ApplyConnectionPoolsJob::class);
    });

    test('a pool larger than 500 is refused', function () {
        Queue::fake([ApplyConnectionPoolsJob::class]);
        $database = Database::factory()->create();
        $database->schemas()->create(['name' => 'shop']);
        writeDatabasesKey();

        $response = mcpCall('create_connection_pool', ['database' => $database->id, 'name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 501]);

        expect(mcpError($response))->toContain('The size field must be between 1 and 500.');
        $this->assertDatabaseMissing('connection_pools', ['name' => 'shop_pool']);
        Queue::assertNotPushed(ApplyConnectionPoolsJob::class);
    });

    test('the pools of a database are listed', function () {
        $database = Database::factory()->create();
        $database->schemas()->create(['name' => 'shop']);
        $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('list_connection_pools', ['database' => $database->id]);

        expect(mcpData($response)[0])->toMatchArray(['name' => 'shop_pool', 'size' => 10]);
    });

    test('a connection pool is deleted', function () {
        Queue::fake([ApplyConnectionPoolsJob::class]);
        $database = Database::factory()->create();
        $database->schemas()->create(['name' => 'shop']);
        $pool = $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
        writeDatabasesKey();

        $response = mcpCall('delete_connection_pool', ['database' => $database->id, 'connection_pool' => $pool->id]);

        expect(mcpData($response)['id'])->toBe($database->id);
        $this->assertModelMissing($pool);
        Queue::assertPushed(ApplyConnectionPoolsJob::class);
    });

    test('a pool of another database is not found and kept', function () {
        $database = Database::factory()->create();
        $other = Database::factory()->create();
        $other->schemas()->create(['name' => 'shop']);
        $pool = $other->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
        writeDatabasesKey();

        $response = mcpCall('delete_connection_pool', ['database' => $database->id, 'connection_pool' => $pool->id]);

        expect(mcpError($response))->toContain('No connection_pool with this id.');
        $this->assertModelExists($pool);
    });

    test('a read-only node gets its own pool', function () {
        Queue::fake([ApplyNodeConnectionPoolsJob::class]);
        $database = Database::factory()->create();
        $database->schemas()->create(['name' => 'shop']);
        $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create();
        writeDatabasesKey();

        $response = mcpCall('create_connection_pool', ['database' => $database->id, 'node' => $readOnly->id, 'name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'session', 'size' => 5]);

        expect(mcpData($response)['id'])->toBe($readOnly->id);
        $this->assertDatabaseHas('connection_pools', ['database_node_id' => $readOnly->id, 'name' => 'shop_pool', 'mode' => 'session']);
        Queue::assertPushed(ApplyNodeConnectionPoolsJob::class);
    });
});

describe('trusted sources', function () {
    test('a single address is trusted as a /32', function () {
        Queue::fake([ApplyTrustedSourcesJob::class]);
        $database = Database::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('add_trusted_source', ['database' => $database->id, 'cidr' => '203.0.113.7', 'label' => 'office']);

        expect(mcpData($response)['status'])->toBe('updating');
        $this->assertDatabaseHas('trusted_sources', ['database_id' => $database->id, 'cidr' => '203.0.113.7/32', 'label' => 'office']);
        Queue::assertPushed(ApplyTrustedSourcesJob::class);
    });

    test('a /0 range open to the whole internet is refused', function () {
        Queue::fake([ApplyTrustedSourcesJob::class]);
        $database = Database::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('add_trusted_source', ['database' => $database->id, 'cidr' => '0.0.0.0/0']);

        expect(mcpError($response))->toContain('Enter an IPv4 address or CIDR range, like 203.0.113.7 or 203.0.113.0/24.');
        $this->assertDatabaseMissing('trusted_sources', ['database_id' => $database->id]);
        Queue::assertNotPushed(ApplyTrustedSourcesJob::class);
    });

    test('the trusted sources of a database are listed', function () {
        $database = Database::factory()->create();
        $database->trustedSources()->create(['cidr' => '203.0.113.7/32', 'label' => 'office']);
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

        $response = mcpCall('list_trusted_sources', ['database' => $database->id]);

        expect(mcpData($response)[0])->toMatchArray(['cidr' => '203.0.113.7/32', 'label' => 'office']);
    });

    test('a trusted source is removed', function () {
        Queue::fake([ApplyTrustedSourcesJob::class]);
        $database = Database::factory()->create();
        $source = $database->trustedSources()->create(['cidr' => '203.0.113.7/32']);
        writeDatabasesKey();

        $response = mcpCall('delete_trusted_source', ['database' => $database->id, 'trusted_source' => $source->id]);

        expect(mcpData($response)['id'])->toBe($database->id);
        $this->assertModelMissing($source);
        Queue::assertPushed(ApplyTrustedSourcesJob::class);
    });

    test('a node trusted source is not removed through the database', function () {
        $readOnly = DatabaseNode::factory()->readOnly()->create();
        $source = $readOnly->trustedSources()->create(['database_id' => $readOnly->database_id, 'cidr' => '198.51.100.0/24']);
        writeDatabasesKey();

        $response = mcpCall('delete_trusted_source', ['database' => $readOnly->database_id, 'trusted_source' => $source->id]);

        expect(mcpError($response))->toContain('No trusted_source with this id.');
        $this->assertModelExists($source);
    });

    test('a read-only node keeps its own trusted sources', function () {
        Queue::fake([ApplyNodeTrustedSourcesJob::class]);
        $readOnly = DatabaseNode::factory()->readOnly()->create();
        writeDatabasesKey();

        $response = mcpCall('add_trusted_source', ['database' => $readOnly->database_id, 'node' => $readOnly->id, 'cidr' => '198.51.100.0/24']);

        expect(mcpData($response)['status'])->toBe('updating');
        $this->assertDatabaseHas('trusted_sources', ['database_node_id' => $readOnly->id, 'cidr' => '198.51.100.0/24']);
        Queue::assertPushed(ApplyNodeTrustedSourcesJob::class);
    });
});

describe('database settings', function () {
    test('database config is saved and applied', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create(['config_fields' => [mcpMaxConnectionsField()]]);
        writeDatabasesKey();

        $response = mcpCall('update_database_config', ['database' => $database->id, 'config' => ['max_connections' => '250']]);

        expect(mcpData($response))->toMatchArray(['status' => 'updating'])
            ->and(mcpData($response)['config']['max_connections'])->toBe('250');
        Queue::assertPushed(ApplySettingsJob::class);
    });

    test('a config value outside the engine range is refused', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create(['config_fields' => [mcpMaxConnectionsField()]]);
        writeDatabasesKey();

        $response = mcpCall('update_database_config', ['database' => $database->id, 'config' => ['max_connections' => '5']]);

        expect(mcpError($response))->toContain('Max connections must be between 10 and 1000.');
        Queue::assertNotPushed(ApplySettingsJob::class);
    });

    test('persistence is turned off for a key-value engine', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->engine('valkey', '9')->create();
        writeDatabasesKey();

        $response = mcpCall('update_persistence', ['database' => $database->id, 'persistence' => 'off']);

        expect(mcpData($response)['persistence'])->toBe('off');
        Queue::assertPushed(ApplySettingsJob::class);
    });

    test('an engine that always persists refuses a persistence change', function () {
        Queue::fake([ApplySettingsJob::class]);
        $database = Database::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('update_persistence', ['database' => $database->id, 'persistence' => 'off']);

        expect(mcpError($response))->toContain('PostgreSQL always keeps its data on disk.');
        Queue::assertNotPushed(ApplySettingsJob::class);
    });

    test('the maintenance window moves', function () {
        $database = Database::factory()->create();
        writeDatabasesKey();

        $response = mcpCall('update_maintenance_window', ['database' => $database->id, 'day' => 6, 'hour' => 23]);

        expect(mcpData($response)['id'])->toBe($database->id);
        $this->assertDatabaseHas('databases', ['id' => $database->id, 'maintenance_day' => 6, 'maintenance_hour' => 23]);
    });

    test('a database moves to a domain and is redeployed', function () {
        Queue::fake([UpgradeDatabaseJob::class]);
        Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['result' => []])]);
        $database = Database::factory()->create();
        $zone = DnsZone::factory()->create(['external_id' => 'zone-1']);
        writeDatabasesKey();

        $response = mcpCall('update_database_domain', ['database' => $database->id, 'dns_zone_id' => $zone->id]);

        expect(mcpData($response))->toMatchArray(['dns_zone_id' => $zone->id, 'status' => 'redeploying']);
        $this->assertDatabaseHas('databases', ['id' => $database->id, 'dns_zone_id' => $zone->id]);
        Queue::assertPushed(UpgradeDatabaseJob::class);
    });
});
