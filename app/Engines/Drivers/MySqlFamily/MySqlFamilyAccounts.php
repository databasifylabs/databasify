<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Contracts\Accounts;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Engines\Scripts\Shell;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MySqlFamilyAccounts implements Accounts
{
    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
    ) {}

    public function list(): AccountList
    {
        $engine = $this->driver->engine();
        $reply = $this->scripts->read($this->driver, 'mysql-family/accounts/list');

        return new AccountList($this->withoutReserved($reply->strings('users'), $engine->reservedUsers), $this->withoutReserved($reply->strings('databases'), $engine->reservedDatabases));
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedDatabases, $name);
        $this->scripts->run($this->driver, 'mysql-family/accounts/create-database', ['NAME' => Shell::withoutNul($name)]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedDatabases, $name);
        $this->scripts->run($this->driver, 'mysql-family/accounts/drop-database', ['NAME' => Shell::withoutNul($name)]);
    }

    public function createUser(string $name, string $password, array $grants, ?string $encryption): void
    {
        $env = [...$this->identifiedEnv($password, $encryption), ...$this->grantEnv($name, $grants)];

        $this->scripts->run($this->driver, 'mysql-family/accounts/create-user', $env);
    }

    public function setPassword(string $name, string $password, ?string $encryption): void
    {
        $env = $this->identifiedEnv($password, $encryption);
        $this->refuseReserved($this->driver->engine()->reservedUsers, $name);
        $this->scripts->run($this->driver, 'mysql-family/accounts/set-password', [...$env, 'NAME' => Shell::withoutNul($name)]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->scripts->run($this->driver, 'mysql-family/accounts/grant', [...$this->grantEnv($name, $grants), 'REPLICATION' => $replication ? 'true' : 'false']);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedUsers, $name);
        $this->scripts->run($this->driver, 'mysql-family/accounts/drop-user', ['NAME' => Shell::withoutNul($name)]);
    }

    /**
     * @return array{PASSWORD: string, IDENTIFIED_BY: string, IDENTIFIED_CLOSE: string}
     */
    private function identifiedEnv(string $password, ?string $requested): array
    {
        $engine = $this->driver->engine();
        $encryption = $requested ?? $engine->defaultEncryption;

        if (! in_array($encryption, $engine->encryptionsOn($this->driver->node->database->version_line), true)) {
            throw new InvalidEngineRequest('invalid_request', 'password encryption '.json_encode($encryption, JSON_THROW_ON_ERROR).' is not offered');
        }

        [$by, $close] = $engine->identifiedWith($encryption);

        return ['PASSWORD' => Shell::withoutNul($password), 'IDENTIFIED_BY' => $by, 'IDENTIFIED_CLOSE' => $close];
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{NAME: string, GRANTS: string, RESERVED_DATABASES: string}
     */
    private function grantEnv(string $name, array $grants): array
    {
        $engine = $this->driver->engine();
        $this->refuseReserved($engine->reservedUsers, $name);
        $this->refuseReserved($engine->reservedDatabases, ...array_map(fn (Grant $grant): string => Shell::withoutNul($grant->database), $grants));

        return [
            'NAME' => Shell::withoutNul($name),
            'GRANTS' => json_encode(array_map(fn (Grant $grant): array => $grant->toPayload(), $grants), JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR),
        ];
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
