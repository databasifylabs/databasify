<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['databasify.plugins.path' => storage_path('framework/testing/plugins-'.Str::random(8))]);
});

afterEach(function () {
    File::deleteDirectory(Plugin::root());
});

function pluginZip(array $databasify = [], string $name = 'acme/hello'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'plugin').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('composer.json', json_encode([
        'name' => $name,
        'version' => '1.0.0',
        'authors' => [['name' => 'Acme']],
        'autoload' => ['psr-4' => ['Acme\\Hello\\' => 'src/']],
        'extra' => ['databasify' => ['plugin' => 'Acme\\Hello\\HelloPlugin', ...$databasify]],
    ]));
    $zip->addFromString('src/HelloPlugin.php', "<?php\n\nnamespace Acme\\Hello;\n\nclass HelloPlugin extends \\App\\Plugins\\AbstractPlugin {}\n");
    $zip->addFromString('public/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $zip->close();

    return new UploadedFile($path, 'hello.zip', 'application/zip', null, true);
}

function withPluginFiles(Plugin $plugin): Plugin
{
    File::ensureDirectoryExists("{$plugin->directory()}/public");
    File::put("{$plugin->directory()}/public/icon.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');

    return $plugin;
}

test('plugins page is displayed', function () {
    withPluginFiles(Plugin::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('plugins.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('plugins/index')->has('plugins', 1));
});

test('a plugin can be installed from an uploaded zip', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('plugins.store'), ['archive' => pluginZip()])
        ->assertSessionHasNoErrors();

    $plugin = Plugin::query()->sole();
    expect($plugin)
        ->id->toBe('hello')
        ->author->toBe('Acme')
        ->source->toBe('hello.zip (uploaded)')
        ->and(is_file("{$plugin->directory()}/src/HelloPlugin.php"))->toBeTrue();
});

test('a zip without a databasify manifest is refused', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('plugins.store'), ['archive' => pluginZip(name: 'no-vendor')])
        ->assertSessionHasErrors(['source' => 'This is not a Databasify plugin: its composer.json needs a name, autoload.psr-4 and extra.databasify.plugin.']);

    expect(Plugin::query()->exists())->toBeFalse();
});

test('a plugin for another databasify version is refused', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('plugins.store'), ['archive' => pluginZip(['requires' => '^9.0'])])
        ->assertSessionHasErrors(['source' => 'Needs Databasify ^9.0; this is 0.2.0.']);

    expect(Plugin::query()->exists())->toBeFalse();
});

test('a plugin with community engines needs the warning accepted', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('plugins.store'), ['archive' => pluginZip(['engines' => ['clickhouse']])])
        ->assertSessionHasErrors(['community_engines' => 'Community engine: clickhouse. This engine is built and supported by Acme, not Databasify. Bugs or data loss in it are theirs to fix.']);

    expect(Plugin::query()->exists())->toBeFalse();
});

test('plugin sources must be a github repository or an https link', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('plugins.store'), ['source' => 'http://example.com/plugin.zip'])
        ->assertSessionHasErrors(['source' => 'Enter a GitHub repository (owner/repo) or an https:// link to a zip.']);
});

test('a plugin can be disabled', function () {
    $plugin = withPluginFiles(Plugin::factory()->create());

    $this->actingAs(User::factory()->create())
        ->put(route('plugins.update', $plugin), ['enabled' => false])
        ->assertSessionHasNoErrors();

    expect($plugin->refresh()->enabled)->toBeFalse();
});

test('an uploaded plugin is updated only from a newer zip', function () {
    $plugin = withPluginFiles(Plugin::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('plugins.upgrade', $plugin))
        ->assertSessionHasErrors(['archive' => 'acme/hello was uploaded, so upload its newer zip.']);
});

test('an update that drops an engine still in use is refused', function () {
    $plugin = withPluginFiles(Plugin::factory()->withEngines(['clickhouse'])->create());
    Database::factory()->create(['engine' => 'clickhouse']);

    $this->actingAs(User::factory()->create())
        ->post(route('plugins.upgrade', $plugin), ['archive' => pluginZip()])
        ->assertSessionHasErrors(['source' => 'This update removes the clickhouse engine, still used by 1 database.']);

    expect($plugin->refresh()->engines)->toBe(['clickhouse']);
});

test('a plugin can be uninstalled', function () {
    $plugin = withPluginFiles(Plugin::factory()->create());

    $this->actingAs(User::factory()->create())
        ->delete(route('plugins.destroy', $plugin))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($plugin);
    expect(is_dir($plugin->directory()))->toBeFalse();
});

test('plugin assets are served from the public folder only', function () {
    User::factory()->create();
    $plugin = withPluginFiles(Plugin::factory()->create());
    File::put("{$plugin->directory()}/composer.json", '{"secret": true}');

    $this->get(route('plugins.asset', [$plugin, 'icon.svg']))->assertOk();
    $this->get(route('plugins.asset', [$plugin, '../composer.json']))->assertNotFound();
});
