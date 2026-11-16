<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Contracts\Accounts;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Engines\Scripts\MySqlSql;
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
        $reply = $this->scripts->queryJson($this->driver, 'engines.mysql-family.accounts.list');

        return new AccountList($this->withoutReserved($reply->strings('users'), $engine->reservedUsers), $this->withoutReserved($reply->strings('databases'), $engine->reservedDatabases));
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedDatabases, $name);
        $this->scripts->query($this->driver, 'engines.mysql-family.accounts.create-database', ['name' => $name]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedDatabases, $name);
        $this->scripts->run($this->driver, 'engines.mysql-family.accounts.drop-database', ['name' => $name, 'users' => $this->driver->node->database->userNames()]);
    }

    public function createUser(string $name, string $password, array $grants, ?string $encryption): void
    {
        $this->scripts->run($this->driver, 'engines.mysql-family.accounts.create-user', [...$this->grantData($name, $grants), 'identified' => $this->identifiedBy($password, $encryption)]);
    }

    public function setPassword(string $name, string $password, ?string $encryption): void
    {
        $identified = $this->identifiedBy($password, $encryption);
        $this->refuseReserved($this->driver->engine()->reservedUsers, $name);
        $this->scripts->query($this->driver, 'engines.mysql-family.accounts.set-password', ['name' => $name, 'identified' => $identified]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->scripts->run($this->driver, 'engines.mysql-family.accounts.grant', [...$this->grantData($name, $grants), 'replication' => $replication]);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReserved($this->driver->engine()->reservedUsers, $name);
        $this->scripts->query($this->driver, 'engines.mysql-family.accounts.drop-user', ['name' => $name]);
    }

    private function identifiedBy(string $password, ?string $requested): string
    {
        $engine = $this->driver->engine();
        $encryption = $requested ?? $engine->defaultEncryption;

        if (! in_array($encryption, $engine->encryptionsOn($this->driver->node->database->version_line), true)) {
            throw new InvalidEngineRequest('password encryption '.json_encode($encryption, JSON_THROW_ON_ERROR).' is not offered');
        }

        [$by, $close] = $engine->identifiedWith($encryption);

        return $by.(new MySqlSql)->string($password).$close;
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{name: string, grants: list<Grant>, managed: list<string>}
     */
    private function grantData(string $name, array $grants): array
    {
        $engine = $this->driver->engine();
        $this->refuseReserved($engine->reservedUsers, $name);
        $this->refuseReserved($engine->reservedDatabases, ...array_map(fn (Grant $grant): string => $grant->database, $grants));

        return ['name' => $name, 'grants' => $grants, 'managed' => $this->driver->node->database->schemaNames()];
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
