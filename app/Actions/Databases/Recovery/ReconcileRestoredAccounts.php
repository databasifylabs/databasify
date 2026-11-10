<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\RunOnPrimary;
use App\DTOs\Databases\ServerAccounts;
use App\DTOs\Recovery\RecoverySource;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\UserSetPassword;
use App\Exceptions\Databases\OperationStopped;
use App\Models\Database;
use App\Models\DatabaseUser;
use RuntimeException;
use Throwable;

final readonly class ReconcileRestoredAccounts
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(Database $database): void
    {
        $source = $database->settings->restore;
        if ($source === null || ! $database->managesAccounts()) {
            return;
        }

        try {
            $listed = $this->verifiedAccountsOf($database);
            $database->users()->whereIn('name', $listed->users)->get()->each(fn (DatabaseUser $user) => $this->primary->handle($database, new UserSetPassword($user->name, $user->password, $user->password_encryption), "Reset the restored password of {$user->name}"));
            $database->forgetAccountsMissingFrom($listed);
        } catch (OperationStopped $stopped) {
            throw $stopped;
        } catch (Throwable $exception) {
            $this->logUnverifiedAccountsOf($database, $source, $exception);
        }
    }

    private function verifiedAccountsOf(Database $database): ServerAccounts
    {
        $reply = $this->primary->handle($database, new AccountList, 'Listing the restored users and databases');
        $listed = new ServerAccounts($reply->users, $reply->databases);
        $default = $database->settings->defaultUsername();

        if (! $listed->hasUser($default)) {
            throw new RuntimeException("The list did not include the default user {$default}.");
        }

        return $listed;
    }

    private function logUnverifiedAccountsOf(Database $database, RecoverySource $source, Throwable $exception): void
    {
        $database->primaryServer()->logFailure(
            'Verify restored accounts',
            "Could not verify the users and databases of {$database->name}, so it keeps every one {$source->database} has now. Delete the ones it did not have at the restore point yourself: {$exception->getMessage()}",
            $database,
        );
    }
}
