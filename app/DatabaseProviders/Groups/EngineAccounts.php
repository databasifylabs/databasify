<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DTOs\Databases\ServerAccounts;
use App\Enums\Databases\PasswordEncryption;
use App\Exceptions\Databases\OperationStopped;
use App\Models\DatabaseNode;
use App\Models\DatabaseUser;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

readonly class EngineAccounts extends EngineGroup
{
    public const int GRANT_TIMEOUT = 120;

    public function createDatabase(string $name): void
    {
        $this->runSql("Create database {$name}", $this->accountSql('create-database', ['name' => $name, 'users' => $this->database->userNames()]));
    }

    public function deleteDatabase(string $name): void
    {
        $this->runSql("Delete database {$name}", $this->accountSql('drop-database', ['name' => $name]));
    }

    public function createUser(string $name, string $password, ?PasswordEncryption $encryption = null): void
    {
        $this->runSql("Create user {$name}", $this->accountSql('create-user', [
            'name' => $name,
            'password' => $password,
            'databases' => $this->database->schemaNames(),
            'encryption' => $encryption,
        ]));
    }

    public function updatePassword(string $name, string $password, ?PasswordEncryption $encryption = null): void
    {
        $this->runSql("Reset password for {$name}", $this->passwordResetSql($name, $password, $encryption));
    }

    public function updatePasswordEncryption(string $name, string $password, PasswordEncryption $encryption): void
    {
        $this->runSql("Change password encryption for {$name}", $this->passwordResetSql($name, $password, $encryption));
    }

    public function deleteUser(string $name): void
    {
        $this->runSql("Delete user {$name}", $this->accountSql('drop-user', ['name' => $name, 'databases' => $this->database->schemaNames()]));
    }

    public function runSql(string $log, string $sql): void
    {
        $this->database->ensureIdle();
        $this->database->startOperation($log);

        try {
            $this->runStep($this->primary(), 'accounts.run-sql', [...$this->scriptData()->for(), 'sql' => $sql, 'summary' => "{$log}: done"], $log);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['database' => "{$log} failed: {$exception->getMessage()}"]);
        } finally {
            $this->database->finishOperation();
        }
    }

    public function listAccounts(): ServerAccounts
    {
        $primary = $this->primary();
        $output = $this->runStep($primary, 'accounts.list', $this->scriptData()->for(), "{$primary->server->name}: listing the restored users and databases");

        return $this->accountsIn($output);
    }

    /**
     * @return list<string>
     */
    public function listDatabases(): array
    {
        return $this->accountsIn($this->runStep($this->primary(), 'accounts.list', $this->scriptData()->for()))->databases;
    }

    /**
     * @param  Collection<int, DatabaseUser>  $users
     */
    public function resetPasswordsOf(Collection $users): void
    {
        $primary = $this->primary();
        $sql = $users->map(fn (DatabaseUser $user): string => $this->passwordResetSql($user->name, $user->password, $user->password_encryption))->implode('');

        $this->runStep($primary, 'accounts.run-sql', [...$this->scriptData()->for(), 'sql' => $sql, 'summary' => 'The restored users have the passwords the app holds'], "{$primary->server->name}: resetting the restored users to their passwords");
    }

    /**
     * @return list<string>
     */
    public function reservedUsers(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function reservedDatabases(): array
    {
        return [];
    }

    public function passwordEncryption(): ?PasswordEncryption
    {
        return null;
    }

    /**
     * @return list<PasswordEncryption>
     */
    public function passwordEncryptions(): array
    {
        return $this->passwordEncryption() === null ? [] : PasswordEncryption::cases();
    }

    public function defaultAccountSql(): string
    {
        $settings = $this->database->settings;

        if ($settings->restore !== null) {
            return '';
        }

        $database = $settings->defaultSchema();

        return $this->accountSql('create-database', ['name' => $database, 'users' => []])
            .$this->accountSql('create-user', [
                'name' => $settings->defaultUsername(),
                'password' => $settings->defaultPassword(),
                'databases' => [$database],
                'encryption' => $this->passwordEncryption(),
            ])
            .$this->replicationGrantFor($settings->defaultUsername());
    }

    public function regrantReplicationToDefaultUser(): void
    {
        $name = $this->database->settings->defaultUsername();
        $primary = $this->primaryWithDefaultUser($name);
        $sql = $this->replicationGrantFor($name);

        if ($primary === null || $sql === '') {
            return;
        }

        try {
            $this->runStep($primary, 'accounts.run-sql', [...$this->scriptData()->for(), 'sql' => $sql, 'summary' => "{$name} can be a migration source"], "{$primary->server->name}: granting {$name} replication rights");
        } catch (OperationStopped $stopped) {
            throw $stopped;
        } catch (Throwable $exception) {
            $primary->server->logFailure('Grant replication to the default user', "Could not grant {$name} the rights to be a migration source, redeploy {$this->database->name} to try again: {$exception->getMessage()}", $this->database);
        }
    }

    /**
     * @param  list<string>  $databases
     */
    public function grantDefaultUserOn(array $databases): void
    {
        $name = $this->database->settings->defaultUsername();
        $primary = $this->primaryWithDefaultUser($name);

        if ($primary === null) {
            return;
        }

        $sql = $this->grantSqlOn($databases, $name);

        try {
            $this->runStep($primary, 'accounts.run-sql', [...$this->scriptData()->for(), 'sql' => $sql, 'summary' => "{$name} can use the migrated databases"], "{$primary->server->name}: granting {$name} the migrated databases", self::GRANT_TIMEOUT);
        } catch (OperationStopped $stopped) {
            throw $stopped;
        } catch (Throwable $exception) {
            $primary->server->logFailure('Grant migrated databases to the default user', "Could not grant {$name} the migrated databases, grant them on {$this->database->name} yourself: {$exception->getMessage()}", $this->database);
        }
    }

    private function primaryWithDefaultUser(string $name): ?DatabaseNode
    {
        return $this->database->hasUser($name) ? $this->database->primary() : null;
    }

    /**
     * @param  list<string>  $databases
     */
    private function grantSqlOn(array $databases, string $user): string
    {
        return collect($databases)
            ->map(fn (string $database): string => $this->accountSql('create-database', ['name' => $database, 'users' => [$user]]))
            ->implode('');
    }

    protected function replicationGrantFor(string $name): string
    {
        return $this->accountSql('grant-replication', ['name' => $name]);
    }

    private function accountsIn(string $output): ServerAccounts
    {
        return ServerAccounts::fromOutput($output, $this->reservedUsers(), $this->reservedDatabases());
    }

    private function primary(): DatabaseNode
    {
        return $this->database->primary() ?? throw new RuntimeException("{$this->database->name} has no healthy primary.");
    }

    private function passwordResetSql(string $name, string $password, ?PasswordEncryption $encryption): string
    {
        return $this->accountSql('reset-password', ['name' => $name, 'password' => $password, 'encryption' => $encryption]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function accountSql(string $statement, array $data): string
    {
        return view($this->engine->scriptView("accounts.{$statement}"), [...$this->engine->scriptVariables(), ...$data])->render();
    }
}
