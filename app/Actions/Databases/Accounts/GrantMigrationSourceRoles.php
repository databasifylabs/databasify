<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Engines\Contracts\MigrationSourceRoles;
use App\Models\Database;
use App\Support\Messages\Sentence;
use Throwable;

final readonly class GrantMigrationSourceRoles
{
    public function handle(Database $database): void
    {
        $default = $database->settings->defaultUsername();

        if (! $database->managesAccounts() || $default === '') {
            return;
        }

        $accounts = $database->driver()->accounts();

        if (! $accounts instanceof MigrationSourceRoles) {
            return;
        }

        try {
            $accounts->grantMigrationSourceRoles($default);
        } catch (Throwable $exception) {
            $server = $database->primaryServer();
            $server->logFailure('Grant migration source roles to the default user', "Could not let {$default} serve as a migration source on {$server->name}: ".Sentence::of($exception->getMessage()).' The next upgrade tries again.', $database);
        }
    }
}
