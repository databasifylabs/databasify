<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Drivers\Shared\BinlogArchive;
use App\Models\Database;

final readonly class MysqlArchive extends BinlogArchive
{
    public function __construct(DatabaseDriver $engine, Database $database)
    {
        parent::__construct(
            engine: $engine,
            database: $database,
            root: Mysql::ROOT,
            service: 'mysql',
            client: 'mysql --defaults-extra-file='.Mysql::ROOT.'/conf/root.cnf',
            binlogTool: 'mysqlbinlog',
            backupTool: 'xtrabackup --defaults-extra-file='.Mysql::ROOT.'/conf/root.cnf --datadir='.Mysql::ROOT.'/data',
            streamTool: 'xbstream',
            binlogInfo: 'xtrabackup_binlog_info',
            gtidQuery: "SELECT REPLACE(@@GLOBAL.gtid_executed, '\\n', '')",
        );
    }
}
