<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Contracts\Accounts;
use App\Engines\Contracts\MigrationSourceRoles;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\PasswordEncryption;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MongoDbAccounts implements Accounts, MigrationSourceRoles
{
    public const array RESERVED_USERS = ['root', 'databasify_pbm', '__system'];

    public const array RESERVED_DATABASES = ['admin', 'local', 'config'];

    public const array SOURCE_ROLES = ['backup', 'clusterMonitor', 'readAnyDatabase'];

    public function __construct(
        private MongoDbDriver $driver,
    ) {}

    public function list(): AccountList
    {
        $reply = $this->driver->queryJson('engines.mongodb.accounts.list');
        $users = $this->withoutReserved($reply->strings('users'), self::RESERVED_USERS);
        $databases = $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES);

        return new AccountList($users, $databases);
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->query('engines.mongodb.accounts.create-database', ['name' => $name]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->query('engines.mongodb.accounts.drop-database', ['name' => $name]);
    }

    public function createUser(string $name, string $password, array $grants, ?PasswordEncryption $encryption): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->query('engines.mongodb.accounts.create-user', [
            'name' => $name,
            'password' => $password,
            'encryption' => $this->encryptionFor($password, $encryption)->value,
            'roles' => $this->rolesOf($name, $grants, false),
        ]);
    }

    public function setPassword(string $name, string $password, ?PasswordEncryption $encryption): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->query('engines.mongodb.accounts.set-password', [
            'name' => $name,
            'password' => $password,
            'encryption' => $this->encryptionFor($password, $encryption)->value,
        ]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->query('engines.mongodb.accounts.grant', ['name' => $name, 'roles' => $this->rolesOf($name, $grants, $replication)]);
    }

    public function grantMigrationSourceRoles(string $name): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->query('engines.mongodb.accounts.grant-source-roles', ['name' => $name, 'roles' => $this->sourceRoles()]);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->query('engines.mongodb.accounts.drop-user', ['name' => $name]);
    }

    private function encryptionFor(string $password, ?PasswordEncryption $requested): PasswordEncryption
    {
        $encryption = $requested ?? PasswordEncryption::ScramSha256;

        if (! in_array($encryption, MongoDbDriver::facts($this->driver->node->database->version_line)->passwordEncryptions, true)) {
            throw new InvalidEngineRequest('password encryption '.json_encode($encryption->value, JSON_THROW_ON_ERROR).' is not offered');
        }

        if ($encryption === PasswordEncryption::ScramSha256 && preg_match('/\p{Cc}/u', $password) === 1) {
            throw new InvalidEngineRequest('a SCRAM-SHA-256 password cannot contain control characters such as a newline or tab');
        }

        return $encryption;
    }

    /**
     * @param  list<Grant>  $grants
     * @return list<MongoDbRole>
     */
    private function rolesOf(string $name, array $grants, bool $replication): array
    {
        $this->refuseReserved(self::RESERVED_DATABASES, ...array_map(fn (Grant $grant): string => $grant->database, $grants));
        $withSourceRoles = $replication || $name === $this->driver->node->database->settings->defaultUsername();

        return [
            ...array_map(fn (Grant $grant): MongoDbRole => new MongoDbRole($grant->access === GrantAccess::Write ? 'readWrite' : 'read', $grant->database), $grants),
            ...($withSourceRoles ? $this->sourceRoles() : []),
        ];
    }

    /**
     * @return list<MongoDbRole>
     */
    private function sourceRoles(): array
    {
        return array_map(fn (string $role): MongoDbRole => new MongoDbRole($role, 'admin'), self::SOURCE_ROLES);
    }

    /**
     * @param  list<string>  $reserved
     */
    private function refuseReserved(array $reserved, string ...$names): void
    {
        $taken = collect($names)->first(fn (string $name): bool => in_array($name, $reserved, true));

        if ($taken !== null) {
            throw new InvalidEngineRequest("{$taken} is reserved");
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
        sort($kept, SORT_STRING);

        return $kept;
    }
}
