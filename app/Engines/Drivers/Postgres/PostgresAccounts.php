<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Accounts;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Enums\Databases\PasswordEncryption;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresAccounts implements Accounts
{
    public const array RESERVED_USERS = ['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'];

    public const array RESERVED_DATABASES = ['postgres', 'template0', 'template1'];

    private const string RESERVED_ROLE_PREFIX = 'pg_';

    public function __construct(private PostgresDriver $driver) {}

    public function list(): AccountList
    {
        $reply = $this->driver->queryJson('engines.postgres.accounts.list');

        return new AccountList($this->withoutReserved($reply->strings('users'), self::RESERVED_USERS), $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES));
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->query('engines.postgres.accounts.create-database', ['name' => $name]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->query('engines.postgres.accounts.drop-database', ['name' => $name]);
    }

    public function createUser(string $name, string $password, array $grants, ?PasswordEncryption $encryption): void
    {
        $this->rejectEncryption($encryption);
        $this->driver->query('engines.postgres.accounts.create-user', [...$this->grantData($name, $grants), 'password' => $password]);
    }

    public function setPassword(string $name, string $password, ?PasswordEncryption $encryption): void
    {
        $this->rejectEncryption($encryption);
        $this->refuseReservedAccount($name, []);
        $this->driver->query('engines.postgres.accounts.set-password', ['name' => $name, 'password' => $password]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->driver->query('engines.postgres.accounts.grant', [...$this->grantData($name, $grants), 'replication' => $replication]);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReservedAccount($name, []);
        $this->driver->query('engines.postgres.accounts.drop-user', ['name' => $name, 'managed' => $this->driver->node->database->schemaNames()]);
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{name: string, grants: list<Grant>, managed: list<string>, allowlist: string}
     */
    private function grantData(string $name, array $grants): array
    {
        $this->refuseReservedAccount($name, $grants);

        return ['name' => $name, 'grants' => $grants, 'managed' => $this->driver->node->database->schemaNames(), 'allowlist' => PostgresExtensionCatalog::allowlistFor($this->driver->node->database)];
    }

    /**
     * @param  list<Grant>  $grants
     */
    private function refuseReservedAccount(string $name, array $grants): void
    {
        if (str_starts_with($name, self::RESERVED_ROLE_PREFIX)) {
            throw new InvalidEngineRequest("{$name} is reserved");
        }

        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->refuseReserved(self::RESERVED_DATABASES, ...array_map(fn (Grant $grant): string => $grant->database, $grants));
    }

    /**
     * @param  list<string>  $reserved
     */
    private function refuseReserved(array $reserved, string ...$names): void
    {
        foreach ($names as $name) {
            if (in_array($name, $reserved, true)) {
                throw new InvalidEngineRequest("{$name} is reserved");
            }
        }
    }

    private function rejectEncryption(?PasswordEncryption $encryption): void
    {
        if ($encryption !== null) {
            throw new InvalidEngineRequest('PostgreSQL has no password encryption choice');
        }
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $reserved
     * @return list<string>
     */
    private function withoutReserved(array $names, array $reserved): array
    {
        $kept = array_values(array_diff($names, $reserved));
        sort($kept);

        return $kept;
    }
}
