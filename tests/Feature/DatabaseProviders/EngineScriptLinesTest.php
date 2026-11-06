<?php

declare(strict_types=1);

use App\DTOs\Databases\VersionLine;
use App\Exceptions\Databases\MissingEngineScript;
use App\Models\Database;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @return list<string>
 */
function sharedScriptsOf(string $engine): array
{
    $root = resource_path('views/'.str_replace('.', '/', config()->string("database-provider.providers.{$engine}.scripts")));

    return collect(File::allFiles($root))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->reject(fn (string $path): bool => str_starts_with($path, 'lines/'))
        ->map(fn (string $path): string => str_replace('/', '.', Str::before($path, '.blade.php')))
        ->sort()
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function missingLineScriptsOf(string $engine, string $line): array
{
    $driver = (new Database(['engine' => $engine, 'version_line' => $line]))->provider();

    return collect(sharedScriptsOf($engine))
        ->reject(fn (string $name): bool => rescue(fn (): bool => str_contains($driver->scriptView($name), '.lines.'), false, false))
        ->map(fn (string $name): string => "{$engine} {$line}: {$name}")
        ->values()
        ->all();
}

it('keeps a file for every shared script in the folder of every registered line', function () {
    $lines = collect(Database::engines())
        ->flatMap(fn (string $engine): array => Database::providerFor($engine)->versions()->map(fn (VersionLine $line): string => "{$engine} {$line->value}")->all());
    $missing = $lines->flatMap(fn (string $line): array => missingLineScriptsOf(...explode(' ', $line)));

    expect($lines->all())->toBe(['valkey 8', 'valkey 9', 'redis 8', 'mysql 8.4', 'mysql 9.7', 'postgres 17', 'postgres 18', 'mariadb 11.4', 'mariadb 11.8', 'mongodb 7.0', 'mongodb 8.0', 'mongodb 9.0'])
        ->and(sharedScriptsOf('mysql'))->toContain('files.server', 'processes.server', 'helpers', 'migrations.check')
        ->and($missing->all())->toBe([]);
});

it('resolves every step in the folder of the database line, keyed safely for dot notation', function (string $engine, string $line, string $view) {
    expect((new Database(['engine' => $engine, 'version_line' => $line]))->provider()->scriptView('lifecycle.status'))->toBe($view);
})->with([
    ['mysql', '9.7', 'ssh.engines.mysql.lines.9_7.lifecycle.status'],
    ['mariadb', '11.4', 'ssh.engines.mariadb.lines.11_4.lifecycle.status'],
    ['mongodb', '7.0', 'ssh.engines.mongodb.lines.7_0.lifecycle.status'],
    ['postgres', '17', 'ssh.engines.postgres.lines.17.lifecycle.status'],
    ['valkey', '8', 'ssh.engines.valkey.lines.8.lifecycle.status'],
    ['redis', '8', 'ssh.engines.redis.lines.8.lifecycle.status'],
]);

it('never falls back to the shared script when a line has no file for a step', function () {
    config(['database-provider.providers.mysql.lines' => [...config()->array('database-provider.providers.mysql.lines'), ['value' => '9.9', 'label' => 'MySQL 9.9']]]);

    expect(view()->exists('ssh.engines.mysql.lifecycle.status'))->toBeTrue()
        ->and(fn () => (new Database(['engine' => 'mysql', 'version_line' => '9.9']))->provider()->scriptView('lifecycle.status'))
        ->toThrow(MissingEngineScript::class, 'The mysql engine has no lifecycle.status script: ssh.engines.mysql.lines.9_9.lifecycle.status does not exist.');
});

it('applies a script override only to the line it is registered for', function () {
    config(['database-provider.providers.valkey.script_overrides' => ['9' => ['lifecycle.status' => 'ssh.engines.valkey.lifecycle.status']]]);

    expect((new Database(['engine' => 'valkey', 'version_line' => '9']))->provider()->scriptView('lifecycle.status'))->toBe('ssh.engines.valkey.lifecycle.status')
        ->and((new Database(['engine' => 'valkey', 'version_line' => '8']))->provider()->scriptView('lifecycle.status'))->toBe('ssh.engines.valkey.lines.8.lifecycle.status');
});
