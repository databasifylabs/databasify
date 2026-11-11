<?php

declare(strict_types=1);

use App\Engines\EngineCatalog;
use App\Models\Database;
use App\Models\Plugin;
use App\Models\User;
use App\Providers\EngineServiceProvider;
use App\Services\Plugins\PluginLoader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['databasify.plugins.path' => storage_path('framework/testing/plugins-'.Str::random(8))]);
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory(config()->string('databasify.plugins.path'));
});

/**
 * @param  list<string>|null  $engines
 * @param  list<array{name: string}>  $authors
 */
function pluginZip(string $namespace, string $id, string $register, string $version = '1.2.0', ?string $requires = null, ?array $engines = null, array $authors = []): string
{
    $class = "{$namespace}Plugin";
    $files = [
        'composer.json' => json_encode(array_filter(['name' => "acme/{$id}", 'version' => $version, 'authors' => $authors, 'autoload' => ['psr-4' => ["Tests\\Plugins\\{$namespace}\\" => 'src/']], 'extra' => ['databasify' => array_filter(['plugin' => "Tests\\Plugins\\{$namespace}\\{$class}", 'requires' => $requires, 'engines' => $engines ?? [$id]])]])),
        "src/{$class}.php" => "<?php\nnamespace Tests\\Plugins\\{$namespace};\nfinal class {$class} extends \\App\\Plugins\\AbstractPlugin\n{\n    public function register(): void\n    {\n        \$this->app->instance('{$id}.registered', true);\n    }\n\n    public function boot(): void\n    {\n        \$this->loadViewsFrom(__DIR__.'/../resources/views', '{$id}');\n        \$this->loadMigrationsFrom(__DIR__.'/../database/migrations');\n        {$register}\n    }\n\n    public function install(): void\n    {\n        \\Illuminate\\Support\\Facades\\Schema::create('{$id}_items', fn (\\Illuminate\\Database\\Schema\\Blueprint \$table) => \$table->id());\n    }\n\n    public function uninstall(): void\n    {\n        \\Illuminate\\Support\\Facades\\Schema::dropIfExists('{$id}_items');\n    }\n}\n",
        "resources/views/ssh/engines/{$id}/lines/25_8/lifecycle/start.blade.php" => 'echo started',
        "public/icons/database-providers/{$id}.svg" => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        'public/icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        "database/migrations/2026_01_01_000000_create_{$id}_logs_table.php" => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('{$id}_logs', fn (\\Illuminate\\Database\\Schema\\Blueprint \$table) => \$table->id());\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('{$id}_logs');\n    }\n};\n",
    ];

    $path = storage_path('framework/testing/'.Str::random(8).'.zip');
    File::ensureDirectoryExists(dirname($path));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($files as $name => $contents) {
        $zip->addFromString("acme-{$id}-abc123/{$name}", (string) $contents);
    }

    $zip->close();
    $body = (string) file_get_contents($path);
    File::delete($path);

    return $body;
}

function fakeGithub(string $zip): void
{
    Http::fake([
        'api.github.com/repos/acme/*/releases/latest' => Http::response(['zipball_url' => 'https://api.github.com/repos/acme/plugin/zipball/v1.2.0']),
        'api.github.com/repos/acme/plugin/zipball/v1.2.0' => Http::response($zip),
    ]);
}

it('installs a plugin from a GitHub repository and runs its engine from its own folder', function () {
    fakeGithub(pluginZip('Clickhouse', 'clickhouse', "\$this->databasify()->database('clickhouse')->label('ClickHouse')->image('ghcr.io/acme/databasify-clickhouse')->versions('25.8', [['value' => '25.8', 'label' => 'ClickHouse 25.8']])->register();"));

    $this->post('/plugins', ['source' => 'https://github.com/acme/plugin', 'accepts_community_engines' => true])->assertRedirect()->assertSessionHasNoErrors();

    expect(Plugin::query()->find('clickhouse'))
        ->name->toBe('acme/clickhouse')
        ->version->toBe('1.2.0')
        ->enabled->toBeTrue()
        ->and(app('clickhouse.registered'))->toBeTrue()
        ->and(EngineCatalog::find('clickhouse')->imageRepository)->toBe('ghcr.io/acme/databasify-clickhouse')
        ->and(EngineCatalog::find('mysql')->imageRepository)->toBe('ghcr.io/databasifylabs/databasify-mysql')
        ->and(EngineCatalog::find('clickhouse')->icon)->toBe(asset('plugin-assets/clickhouse/icons/database-providers/clickhouse.svg'));

    $this->get('/plugin-assets/clickhouse/icons/database-providers/clickhouse.svg')->assertOk();
    $this->get('/plugin-assets/clickhouse/composer.json')->assertNotFound();
    $this->get('/plugins')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('plugins/index')
        ->where('plugins.0.id', 'clickhouse')
        ->where('plugins.0.icon', asset('plugin-assets/clickhouse/icon.svg'))
        ->where('plugins.0.uploaded', false)
        ->has('plugins.0', fn (AssertableInertia $plugin) => $plugin->hasAll(['id', 'name', 'version', 'source', 'icon', 'uploaded', 'enabled', 'error', 'community', 'author'])));
    expect(Schema::hasTable('clickhouse_logs'))->toBeTrue();
});

it('loads enabled plugins on boot and skips disabled ones', function () {
    fakeGithub(pluginZip('Keydb', 'keydb', "\$this->databasify()->database('keydb')->label('KeyDB')->register();"));
    $this->post('/plugins', ['source' => 'acme/plugin', 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    $this->put('/plugins/keydb', ['enabled' => false])->assertSessionHasNoErrors();
    resolve(PluginLoader::class)->reset();
    (new EngineServiceProvider(app()))->boot();
    resolve(PluginLoader::class)->boot();
    expect(Database::engines())->not->toContain('keydb');

    $this->put('/plugins/keydb', ['enabled' => true])->assertSessionHasNoErrors();
    resolve(PluginLoader::class)->reset();
    (new EngineServiceProvider(app()))->boot();
    resolve(PluginLoader::class)->boot();
    expect(Database::engines())->toContain('keydb');

    expect(Schema::hasTable('keydb_items'))->toBeTrue();

    $this->put('/plugins/keydb', ['enabled' => false])->assertSessionHasNoErrors();
    $this->get('/plugin-assets/keydb/icon.svg')->assertOk();

    $this->delete('/plugins/keydb')->assertSessionHasNoErrors();
    expect(Schema::hasTable('keydb_items'))->toBeFalse()
        ->and(Schema::hasTable('keydb_logs'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2026_01_01_000000_create_keydb_logs_table')->exists())->toBeFalse();
    expect(Plugin::query()->count())->toBe(0)
        ->and(is_dir(Plugin::root().'/keydb'))->toBeFalse();
});

it('resets only the migrations of the uninstalled plugin, not a sibling sharing its name prefix', function () {
    foreach (['Keydb' => 'keydb', 'Keydbx' => 'keydbx'] as $namespace => $id) {
        $zip = UploadedFile::fake()->createWithContent("{$id}.zip", pluginZip($namespace, $id, "\$this->databasify()->database('{$id}')->label('{$id}')->register();"));
        $this->post('/plugins', ['archive' => $zip, 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    }

    $this->delete('/plugins/keydb')->assertSessionHasNoErrors();

    expect(Schema::hasTable('keydb_logs'))->toBeFalse()
        ->and(Schema::hasTable('keydbx_logs'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2026_01_01_000000_create_keydbx_logs_table')->exists())->toBeTrue();
});

it('does not reset any migrations when the plugin directory is gone', function () {
    $zip = UploadedFile::fake()->createWithContent('keydb.zip', pluginZip('Keydb', 'keydb', "\$this->databasify()->database('keydb')->label('KeyDB')->register();"));
    $this->post('/plugins', ['archive' => $zip, 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    $applied = DB::table('migrations')->count();
    File::deleteDirectory(Plugin::root().'/keydb');

    $this->delete('/plugins/keydb')->assertSessionHasNoErrors();

    expect(DB::table('migrations')->count())->toBe($applied)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Plugin::query()->find('keydb'))->toBeNull();
});

it('refuses a plugin that fails half way and leaves the built-in engines alone', function () {
    fakeGithub(pluginZip('Broken', 'broken', "\$this->databasify()->database('broken')->label('Broken')->register();\n        \$this->databasify()->database('mysql')->label('Not MySQL')->register();", engines: ['broken', 'mysql']));

    $this->post('/plugins', ['source' => 'acme/plugin', 'accepts_community_engines' => true])->assertSessionHasErrors(['source' => 'The plugin failed to load: A provider is already registered as "mysql".']);

    expect(Plugin::query()->count())->toBe(0)
        ->and(Database::engines())->toBe(['valkey', 'redis', 'mysql', 'postgres', 'mariadb', 'mongodb'])
        ->and(EngineCatalog::find('mysql')->label)->toBe('MySQL');
});

it('installs a plugin from an uploaded zip', function () {
    $zip = UploadedFile::fake()->createWithContent('pgvector.zip', pluginZip('Pgvector', 'pgvector', "\$this->databasify()->database('pgvector')->label('pgvector')->register();"));

    $this->post('/plugins', ['archive' => $zip, 'accepts_community_engines' => true])->assertRedirect()->assertSessionHasNoErrors();

    expect(Plugin::query()->find('pgvector'))->source->toBe('pgvector.zip (uploaded)')
        ->and(EngineCatalog::find('pgvector')->label)->toBe('pgvector');
    $this->post('/plugins', [])->assertSessionHasErrors(['source', 'archive']);
});

it('refuses a zip that is not a Databasify plugin', function () {
    Http::fake(['example.com/*' => Http::response('not a zip')]);

    $this->post('/plugins', ['source' => 'https://example.com/plugin.zip'])->assertSessionHasErrors(['source' => 'https://example.com/plugin.zip is not a zip file.']);
    $this->post('/plugins', ['source' => 'ftp://example.com/plugin.zip'])->assertSessionHasErrors(['source' => 'Enter a GitHub repository (owner/repo) or an https:// link to a zip.']);
});

it('refuses a plugin built for another Databasify version and skips one that stopped matching', function () {
    fakeGithub(pluginZip('Future', 'future', "\$this->databasify()->database('future')->label('Future')->register();", requires: '^9.0'));
    $this->post('/plugins', ['source' => 'acme/plugin', 'accepts_community_engines' => true])->assertSessionHasErrors(['source' => 'Needs Databasify ^9.0; this is 0.1.0.']);
    expect(Plugin::query()->count())->toBe(0);

    $zip = UploadedFile::fake()->createWithContent('current.zip', pluginZip('Current', 'current', "\$this->databasify()->database('current')->label('Current')->register();", requires: '^0.1'));
    $this->post('/plugins', ['archive' => $zip, 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    Plugin::query()->whereKey('current')->update(['requires' => '^9.0']);
    resolve(PluginLoader::class)->reset();
    (new EngineServiceProvider(app()))->boot();
    resolve(PluginLoader::class)->boot();

    expect(Database::engines())->not->toContain('current')
        ->and(Plugin::query()->find('current')?->error)->toBe('Needs Databasify ^9.0; this is 0.1.0.');
});

it('updates a plugin in place from its repository', function () {
    Http::fake([
        'api.github.com/repos/acme/plugin/releases/latest' => Http::sequence()
            ->push(['zipball_url' => 'https://api.github.com/repos/acme/plugin/zipball/v1.2.0'])
            ->push(['zipball_url' => 'https://api.github.com/repos/acme/plugin/zipball/v1.3.0'])
            ->push(['zipball_url' => 'https://api.github.com/repos/acme/plugin/zipball/other']),
        'api.github.com/repos/acme/plugin/zipball/v1.2.0' => Http::response(pluginZip('Tidb', 'tidb', "\$this->databasify()->database('tidb')->label('TiDB')->register();")),
        'api.github.com/repos/acme/plugin/zipball/v1.3.0' => Http::response(pluginZip('Tidb', 'tidb', "\$this->databasify()->database('tidb')->label('TiDB')->register();", version: '1.3.0')),
        'api.github.com/repos/acme/plugin/zipball/other' => Http::response(pluginZip('Other', 'other', '')),
    ]);
    $this->post('/plugins', ['source' => 'acme/plugin', 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    $this->post('/plugins/tidb/upgrade')->assertRedirect()->assertSessionHasNoErrors();
    expect(Plugin::query()->find('tidb')?->version)->toBe('1.3.0')
        ->and(glob(Plugin::root().'/.tidb-*'))->toBe([]);

    $this->post('/plugins/tidb/upgrade')->assertSessionHasErrors(['source' => 'This zip is the other plugin, not tidb.']);
    expect(Plugin::query()->find('tidb')?->version)->toBe('1.3.0');
});

it('updates an uploaded plugin from a newer zip', function () {
    $register = "\$this->databasify()->database('vitess')->label('Vitess')->register();";
    $this->post('/plugins', ['archive' => UploadedFile::fake()->createWithContent('vitess.zip', pluginZip('Vitess', 'vitess', $register)), 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    $this->post('/plugins/vitess/upgrade')->assertSessionHasErrors(['archive' => 'acme/vitess was uploaded, so upload its newer zip.']);
    $this->post('/plugins/vitess/upgrade', ['archive' => UploadedFile::fake()->createWithContent('vitess-2.zip', pluginZip('Vitess', 'vitess', $register, version: '2.0.0'))])->assertSessionHasNoErrors();

    expect(Plugin::query()->find('vitess'))->version->toBe('2.0.0')->source->toBe('vitess-2.zip (uploaded)');
});

it('marks an engine a plugin registers as community, with the plugin author', function () {
    $register = "\$this->databasify()->database('clickhouse')->label('ClickHouse')->register();";
    fakeGithub(pluginZip('Clickhouse', 'clickhouse', $register, authors: [['name' => 'Acme Labs']]));
    $this->post('/plugins', ['source' => 'acme/plugin', 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    $this->post('/plugins', ['archive' => UploadedFile::fake()->createWithContent('keydb.zip', pluginZip('Keydb', 'keydb', "\$this->databasify()->database('keydb')->label('KeyDB')->register();")), 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    expect(EngineCatalog::find('clickhouse'))->isCommunity()->toBeTrue()->author->toBe('Acme Labs')
        ->and(EngineCatalog::find('keydb'))->isCommunity()->toBeTrue()->author->toBe('acme')
        ->and(EngineCatalog::find('mysql'))->isCommunity()->toBeFalse()->author->toBeNull();

    $this->get('/plugins')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('plugins.0.id', 'clickhouse')
        ->where('plugins.0.community', true)
        ->where('plugins.0.author', 'Acme Labs'));
    $engines = collect($this->get(projectUrl('/databases/create'))->inertiaProps('engines'))->keyBy('value');
    expect($engines['clickhouse'])->toMatchArray(['community' => true, 'author' => 'Acme Labs'])
        ->and($engines['mysql'])->toMatchArray(['community' => false, 'author' => null]);

    $database = idleDatabase('postgres', 1);
    $database->forceFill(['engine' => 'clickhouse'])->saveQuietly();
    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.engine_community', true)
        ->where('database.data.engine_author', 'Acme Labs'));
});

it('asks the admin to accept a community engine before installing the plugin', function () {
    $zip = fn () => UploadedFile::fake()->createWithContent('clickhouse.zip', pluginZip('Clickhouse', 'clickhouse', "\$this->databasify()->database('clickhouse')->label('ClickHouse')->register();", engines: ['clickhouse', 'chdb'], authors: [['name' => 'Acme Labs']]));

    $this->post('/plugins', ['archive' => $zip()])->assertSessionHasErrors(['community_engines' => 'Community engines: clickhouse, chdb. This engine is built and supported by Acme Labs, not Databasify. Bugs or data loss in it are theirs to fix.']);

    expect(Plugin::query()->count())->toBe(0)
        ->and(app()->bound('clickhouse.registered'))->toBeFalse()
        ->and(is_dir(Plugin::root().'/clickhouse'))->toBeFalse();

    $this->post('/plugins', ['archive' => $zip(), 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    expect(Plugin::query()->find('clickhouse'))->engines->toBe(['clickhouse', 'chdb']);
});

it('asks again when an update declares a new engine', function () {
    $this->post('/plugins', ['archive' => UploadedFile::fake()->createWithContent('tidb.zip', pluginZip('Tidb', 'tidb', "\$this->databasify()->database('tidb')->label('TiDB')->register();")), 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    $newer = fn () => UploadedFile::fake()->createWithContent('tidb-2.zip', pluginZip('Tidb', 'tidb', "\$this->databasify()->database('tidb')->label('TiDB')->register();\n        \$this->databasify()->database('tikv')->label('TiKV')->register();", version: '2.0.0', engines: ['tidb', 'tikv']));

    $this->post('/plugins/tidb/upgrade', ['archive' => $newer()])->assertSessionHasErrors(['community_engines' => 'Community engine: tikv. This engine is built and supported by acme, not Databasify. Bugs or data loss in it are theirs to fix.']);
    expect(Plugin::query()->find('tidb')?->version)->toBe('1.2.0');

    $this->post('/plugins/tidb/upgrade', ['archive' => $newer(), 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    expect(Plugin::query()->find('tidb'))->version->toBe('2.0.0')->engines->toBe(['tidb', 'tikv']);
});

it('refuses an update that drops an engine a database still uses', function () {
    $register = "\$this->databasify()->database('tidb')->label('TiDB')->register();";
    $this->post('/plugins', ['archive' => UploadedFile::fake()->createWithContent('tidb.zip', pluginZip('Tidb', 'tidb', "{$register}\n        \$this->databasify()->database('tikv')->label('TiKV')->register();", engines: ['tidb', 'tikv'])), 'accepts_community_engines' => true])->assertSessionHasNoErrors();
    idleDatabase('postgres', 1)->forceFill(['engine' => 'tikv'])->saveQuietly();

    $this->post('/plugins/tidb/upgrade', ['archive' => UploadedFile::fake()->createWithContent('tidb-2.zip', pluginZip('Tidb', 'tidb', $register, version: '2.0.0'))])
        ->assertSessionHasErrors(['source' => 'This update removes the tikv engine, still used by 1 database.']);

    expect(Plugin::query()->find('tidb')?->version)->toBe('1.2.0');
});

it('fails the boot of a plugin that registers an engine it did not declare', function () {
    fakeGithub(pluginZip('Clickhouse', 'clickhouse', "\$this->databasify()->database('clickhouse')->label('ClickHouse')->register();", engines: []));

    $this->post('/plugins', ['source' => 'acme/plugin'])->assertSessionHasErrors(['source' => 'The plugin failed to load: Declare the "clickhouse" engine in extra.databasify.engines before registering it.']);

    expect(Plugin::query()->count())->toBe(0)
        ->and(Database::engines())->not->toContain('clickhouse');
});

/**
 * @param  list<string>  $engines
 */
function directEngineZip(array $engines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('direct.zip', pluginZip('Direct', 'direct', "\\App\\Plugins\\RegisterEngine::make('x')->label('X')->register();", engines: $engines, authors: [['name' => 'Acme Labs']]));
}

it('fails the boot of a plugin that registers an undeclared engine without its context', function () {
    $this->post('/plugins', ['archive' => directEngineZip([])])->assertSessionHasErrors(['source' => 'The plugin failed to load: Declare the "x" engine in extra.databasify.engines before registering it.']);

    expect(Database::engines())->not->toContain('x');
});

it('stamps a declared engine a plugin registers without its context as community', function () {
    $this->post('/plugins', ['archive' => directEngineZip(['x']), 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    expect(EngineCatalog::find('x'))->isCommunity()->toBeTrue()->author->toBe('Acme Labs');
});

it('fails the boot of a plugin that writes an undeclared engine into the config', function () {
    $zip = UploadedFile::fake()->createWithContent('raw.zip', pluginZip('Raw', 'raw', "config(['engine.providers.y' => ['label' => 'Y']]);", engines: []));

    $this->post('/plugins', ['archive' => $zip])->assertSessionHasErrors(['source' => 'The plugin failed to load: Declare the "y" engine in extra.databasify.engines before registering it.']);

    expect(Database::engines())->not->toContain('y');
});

it('shows the real author of a plugin that sets its own', function () {
    $register = "\$this->databasify()->database('z')->label('Z')->register();\n        config(['engine.providers.z.author' => 'Databasify', 'engine.providers.z.plugin' => 'core']);";
    $zip = UploadedFile::fake()->createWithContent('z.zip', pluginZip('Zed', 'zed', $register, engines: ['z'], authors: [['name' => 'Acme Labs']]));

    $this->post('/plugins', ['archive' => $zip, 'accepts_community_engines' => true])->assertSessionHasNoErrors();

    expect(EngineCatalog::find('z'))->author->toBe('Acme Labs')->plugin->toBe('zed');
});
