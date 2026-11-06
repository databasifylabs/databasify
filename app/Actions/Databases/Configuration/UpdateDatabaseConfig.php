<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\Jobs\Databases\ApplyDatabaseConfigJob;
use App\Models\Database;

final readonly class UpdateDatabaseConfig
{
    private const string TITLE = 'Apply settings';

    public function __construct(private RedeployDatabase $redeploy) {}

    /**
     * @param  array<string, string>  $config
     */
    public function handle(Database $database, array $config): void
    {
        $database->ensureIdle();
        $changed = $database->updateConfig($config);

        if ($database->configChangeNeedsRestart($changed)) {
            $this->redeploy->handle($database, self::TITLE);

            return;
        }

        $database->startOperation(self::TITLE);
        dispatch(new ApplyDatabaseConfigJob($database, $changed));
    }
}
