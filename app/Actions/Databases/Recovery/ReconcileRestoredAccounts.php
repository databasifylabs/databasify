<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Models\Database;
use App\Models\DatabaseUser;
use RuntimeException;
use Throwable;

final readonly class ReconcileRestoredAccounts
{
    public function handle(Database $database): void
    {
        $source = $database->settings->restore;

        if ($source === null || ! $database->managesAccounts()) {
            return;
        }

        try {
            $listed = $database->driver()->accounts()->list();
            $default = $database->settings->defaultUsername();

            if (! $listed->hasUser($default)) {
                throw new RuntimeException("The list did not include the default user {$default}.");
            }

            $database->users()->whereIn('name', $listed->users)->get()
                ->each(fn (DatabaseUser $user) => $database->driver()->accounts()->setPassword($user->name, $user->password, $user->password_encryption));
            $database->forgetAccountsMissingFrom($listed);
        } catch (Throwable $exception) {
            $database->primaryServer()->logFailure(
                'Verify restored accounts',
                "Could not verify the users and databases of {$database->name}, so it keeps every one {$source->database} has now. Delete the ones it did not have at the restore point yourself: {$exception->getMessage()}",
                $database,
            );
        }
    }
}
