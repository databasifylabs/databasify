<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Enums\Databases\PasswordEncryption;

interface Accounts
{
    public function list(): AccountList;

    public function createDatabase(string $name): void;

    public function dropDatabase(string $name): void;

    /**
     * @param  list<Grant>  $grants
     */
    public function createUser(string $name, string $password, array $grants, ?PasswordEncryption $encryption): void;

    public function setPassword(string $name, string $password, ?PasswordEncryption $encryption): void;

    /**
     * @param  list<Grant>  $grants
     */
    public function grant(string $name, array $grants, bool $replication): void;

    public function dropUser(string $name): void;
}
