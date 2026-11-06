<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationProgress;
use RuntimeException;

abstract readonly class BinlogMigration extends EngineMigration
{
    protected const string BINARY_LOG_OFF = 'Binary logging is off on the source (log_bin).';

    protected const string ROW_FORMAT_NEEDED = 'The source must use binlog_format=ROW.';

    protected const string ALL_PRIVILEGES = 'ALL PRIVILEGES ON *.*';

    protected function ensureReplicableFrom(string $file, string $position): void
    {
        throw_if($file === '' || ! ctype_digit($position), RuntimeException::class, 'The dump has no binlog position, so the source cannot be replicated.');
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function replicationProgressOf(array $values, bool $running, string $lag, string $reconnecting): MigrationProgress
    {
        $error = trim(($values['Last_IO_Error'] ?? '').' '.($values['Last_SQL_Error'] ?? ''));

        if (! $running) {
            return MigrationProgress::stalled($error !== '' ? $error : $reconnecting);
        }

        return MigrationProgress::caughtUp("Replicating, {$lag}s behind the source.", ctype_digit($lag) ? (int) $lag : null);
    }
}
