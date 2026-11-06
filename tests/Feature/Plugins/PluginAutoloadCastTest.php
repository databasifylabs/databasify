<?php

declare(strict_types=1);

use App\DTOs\Plugins\PluginAutoload;
use App\Models\Plugin;
use Illuminate\Support\Facades\DB;

it('reads an autoload saved before the cast', function () {
    $plugin = Plugin::query()->create(['id' => 'acme/backup', 'name' => 'Backup', 'version' => '1.0.0', 'source' => 'local', 'class' => 'Acme\\Backup', 'autoload' => new PluginAutoload([]), 'enabled' => true]);

    DB::table('plugins')->where('id', $plugin->id)->update(['autoload' => '{"Acme\\\\Backup\\\\":"src\/"}']);

    expect($plugin->fresh()?->autoload)->toEqual(new PluginAutoload(['Acme\\Backup\\' => 'src/']));
});

it('stores the autoload as the same json map', function () {
    $plugin = Plugin::query()->create(['id' => 'acme/backup', 'name' => 'Backup', 'version' => '1.0.0', 'source' => 'local', 'class' => 'Acme\\Backup', 'autoload' => new PluginAutoload(['Acme\\Backup\\' => 'src/']), 'enabled' => true]);

    expect(json_decode((string) DB::table('plugins')->where('id', $plugin->id)->value('autoload'), true))->toBe(['Acme\\Backup\\' => 'src/']);
});
