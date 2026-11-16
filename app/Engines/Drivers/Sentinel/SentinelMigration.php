<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Data\Operation;
use Illuminate\Support\Str;

final readonly class SentinelMigration implements Migration
{
    public function __construct(
        private SentinelDriver $driver,
        private SentinelConfiguration $configuration,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $facts = $this->driver->read('engines.sentinel.migration.check', env: [
            'SOURCE_HOST' => $source->host,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_USER' => $source->username === 'default' ? '' : $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_TLS' => $source->tls ? 'true' : '',
        ], timeout: 300);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::unreachable($error);
        }

        $info = new SentinelReply($facts->string('info'));
        $warnings = array_values(collect($this->modulesIn($info))
            ->diff($this->modulesIn(new SentinelReply($facts->string('modules'))))
            ->map(fn (string $name): string => "the source loads the {$name} module, whose data types this server cannot store")
            ->all());

        return SourceReport::checked($info->fieldOf('valkey_version') ?? $info->fieldOf('redis_version'), true, $info->megabytesOf('used_memory'), [], warnings: $warnings);
    }

    public function start(MigrationStart $start): void
    {
        $password = (string) $this->configuration->savedRequestOf()->secrets->admin;
        $directory = $this->driver::flavor()->home().'/migration';

        $this->driver->run('engines.sentinel.migration.start', ['shake' => SentinelFiles::shakeConfigOf($start, $directory, $password)]);
    }

    public function progress(): MigrationProgress
    {
        $reply = $this->driver->read('engines.sentinel.migration.progress');

        if ($reply->string('loadState') === 'not-found') {
            return MigrationProgress::stalled('');
        }

        $log = Str::of($reply->string('log'))->replace("\n", ' ')->replaceMatches('/\e\[[0-9;]*m/', '')->value();
        $running = $reply->string('activeState') === 'active' && $reply->string('subState') === 'running';
        $synced = str_contains($log, 'syncing aof, diff=[0]');

        return match (true) {
            $running && $synced => MigrationProgress::caughtUp($log, 0),
            $running => MigrationProgress::working($log),
            $reply->string('exitStatus') === '0' => MigrationProgress::caughtUp($log, $synced ? 0 : null),
            default => MigrationProgress::broken($log),
        };
    }

    public function stop(string $key, bool $cutover): Operation
    {
        return $this->driver->task($key, 'engines.sentinel.migration.stop');
    }

    /**
     * @return list<string>
     */
    private function modulesIn(SentinelReply $info): array
    {
        return array_map(fn (string $module): string => Str::before(Str::after($module, 'name='), ','), $info->fieldsOf('module'));
    }
}
