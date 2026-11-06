<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DatabaseProviders\Groups\EngineAccounts;
use App\DTOs\Databases\ServerAccounts;
use App\DTOs\Recovery\RecoverySource;
use App\Exceptions\Databases\OperationStopped;
use App\Models\Database;
use RuntimeException;
use Throwable;

final readonly class ReconcileRestoredAccounts
{
    public function handle(Database $database): void
    {
        $source = $database->settings->restore;
        $accounts = $database->provider()->accounts();

        if ($source === null || $accounts === null) {
            return;
        }

        try {
            $listed = $this->verifiedAccountsOf($database, $accounts);
            $accounts->resetPasswordsOf($database->users()->whereIn('name', $listed->users)->get());
            $database->forgetAccountsMissingFrom($listed);
        } catch (OperationStopped $stopped) {
            throw $stopped;
        } catch (Throwable $exception) {
            $this->logUnverifiedAccountsOf($database, $source, $exception);
        }
    }

    private function verifiedAccountsOf(Database $database, EngineAccounts $accounts): ServerAccounts
    {
        $listed = $accounts->listAccounts();
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
