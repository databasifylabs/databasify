<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Accounts;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresAccounts implements Accounts
{
    public const array RESERVED_USERS = ['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'];

    public const array RESERVED_DATABASES = ['postgres', 'template0', 'template1'];

    private const string RESERVED_ROLE_PREFIX = 'pg_';

    public function __construct(private PostgresDriver $driver) {}

    public function list(): AccountList
    {
        $reply = $this->driver->read('postgres/accounts/list', $this->driver->data());

        return new AccountList($this->withoutReserved($reply->strings('users'), self::RESERVED_USERS), $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES));
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->run('postgres/accounts/create-database', $this->driver->data(), env: ['NAME' => $name]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->run('postgres/accounts/drop-database', $this->driver->data(), env: ['NAME' => $name]);
    }

    public function createUser(string $name, string $password, array $grants, ?string $encryption): void
    {
        $this->rejectEncryption($encryption);
        $this->refuseReservedAccount($name, $grants);
        $this->driver->run('postgres/accounts/create-user', $this->driver->data(), env: [...$this->grantEnv($name, $grants), 'PASSWORD' => $password]);
    }

    public function setPassword(string $name, string $password, ?string $encryption): void
    {
        $this->rejectEncryption($encryption);
        $this->refuseReservedAccount($name, []);
        $this->driver->run('postgres/accounts/set-password', $this->driver->data(), env: ['NAME' => $name, 'PASSWORD' => $password]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->refuseReservedAccount($name, $grants);
        $this->driver->run('postgres/accounts/grant', $this->driver->data(), env: [...$this->grantEnv($name, $grants), 'REPLICATION' => $replication ? 'true' : 'false']);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReservedAccount($name, []);
        $this->driver->run('postgres/accounts/drop-user', $this->driver->data(), env: ['NAME' => $name]);
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{NAME: string, GRANTS: string, RESERVED_DATABASES: string}
     */
    private function grantEnv(string $name, array $grants): array
    {
        return [
            'NAME' => $name,
            'GRANTS' => json_encode(array_map(fn (Grant $grant): array => $grant->toPayload(), $grants), JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode(self::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  list<Grant>  $grants
     */
    private function refuseReservedAccount(string $name, array $grants): void
    {
        if (str_starts_with($name, self::RESERVED_ROLE_PREFIX)) {
            throw new InvalidEngineRequest('invalid_request', "{$name} is reserved");
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
                throw new InvalidEngineRequest('invalid_request', "{$name} is reserved");
            }
        }
    }

    private function rejectEncryption(?string $encryption): void
    {
        if ($encryption !== null) {
            throw new InvalidEngineRequest('invalid_request', 'PostgreSQL has no password encryption choice');
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
