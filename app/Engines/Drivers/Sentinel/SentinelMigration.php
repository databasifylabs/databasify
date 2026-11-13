<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;

final readonly class SentinelMigration implements Migration
{
    public function __construct(
        private SentinelDriver $driver,
        private SentinelScripts $scripts,
        private SentinelConfiguration $configuration,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $facts = $this->scripts->read($this->driver, 'migration/check', [
            'SOURCE_HOST' => $source->host,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_USER' => $source->username === 'default' ? '' : $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_TLS' => $source->tls ? 'true' : '',
        ], 300);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::unreachable($error);
        }

        return SourceReport::checked($facts->nullableString('version'), true, $facts->nullableInt('sizeMb'), [], warnings: $facts->strings('warnings'));
    }

    public function start(MigrationStart $start): void
    {
        $password = (string) $this->configuration->savedRequestOf()->secrets->admin;
        $directory = $this->driver::flavor()->home().'/migration';

        $this->scripts->run($this->driver, 'migration/start', ['SHAKE' => base64_encode(SentinelFiles::shakeConfigOf($start, $directory, $password))]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromPayload(($this->scripts->read($this->driver, 'migration/progress')));
    }

    public function stop(): void
    {
        $this->scripts->run($this->driver, 'migration/stop');
    }
}
