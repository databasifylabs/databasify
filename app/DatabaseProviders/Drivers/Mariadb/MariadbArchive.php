<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Drivers\Shared\BinlogArchive;
use App\Models\Database;

final readonly class MariadbArchive extends BinlogArchive
{
    public function __construct(DatabaseDriver $engine, Database $database)
    {
        parent::__construct(
            engine: $engine,
            database: $database,
            root: Mariadb::ROOT,
            service: 'mariadb',
            client: 'mariadb',
            binlogTool: 'mariadb-binlog',
            backupTool: 'mariadb-backup --defaults-extra-file='.Mariadb::CONFIG,
            streamTool: 'mbstream',
            binlogInfo: 'mariadb_backup_binlog_info',
            gtidQuery: 'SELECT @@GLOBAL.gtid_binlog_pos',
        );
    }
}
