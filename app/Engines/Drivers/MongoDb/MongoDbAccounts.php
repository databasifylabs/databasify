<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Contracts\Accounts;
use App\Engines\Data\AccountList;
use App\Engines\Data\Grant;
use App\Engines\Scripts\Shell;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MongoDbAccounts implements Accounts
{
    public const array RESERVED_USERS = ['root', 'databasify_pbm', '__system'];

    public const array RESERVED_DATABASES = ['admin', 'local', 'config'];

    private const string SCRAM_SHA_256 = 'SCRAM-SHA-256';

    private const array ENCRYPTIONS = [self::SCRAM_SHA_256, 'SCRAM-SHA-1'];

    public function __construct(
        private MongoDbDriver $driver,
    ) {}

    public function list(): AccountList
    {
        $reply = $this->driver->read('engines.mongodb.accounts.list');
        $users = $this->withoutReserved($reply->strings('users'), self::RESERVED_USERS);
        $databases = $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES);

        return new AccountList($users, $databases);
    }

    public function createDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->run('engines.mongodb.accounts.create-database', env: ['NAME' => Shell::withoutNul($name)]);
    }

    public function dropDatabase(string $name): void
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $name);
        $this->driver->run('engines.mongodb.accounts.drop-database', env: ['NAME' => Shell::withoutNul($name)]);
    }

    public function createUser(string $name, string $password, array $grants, ?string $encryption): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $env = [...$this->passwordEnv($password, $encryption), ...$this->grantEnv($name, $grants)];

        $this->driver->run('engines.mongodb.accounts.create-user', env: $env);
    }

    public function setPassword(string $name, string $password, ?string $encryption): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->run('engines.mongodb.accounts.set-password', env: [...$this->passwordEnv($password, $encryption), 'NAME' => Shell::withoutNul($name)]);
    }

    public function grant(string $name, array $grants, bool $replication): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->run('engines.mongodb.accounts.grant', env: [...$this->grantEnv($name, $grants), 'REPLICATION' => $replication ? 'true' : 'false']);
    }

    public function dropUser(string $name): void
    {
        $this->refuseReserved(self::RESERVED_USERS, $name);
        $this->driver->run('engines.mongodb.accounts.drop-user', env: ['NAME' => Shell::withoutNul($name)]);
    }

    /**
     * @return array{PASSWORD: string, ENCRYPTION: string}
     */
    private function passwordEnv(string $password, ?string $requested): array
    {
        $encryption = $requested ?? self::SCRAM_SHA_256;

        if (! in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidEngineRequest('invalid_request', 'password encryption '.json_encode($encryption, JSON_THROW_ON_ERROR).' is not offered');
        }

        if ($encryption === self::SCRAM_SHA_256 && preg_match('/\p{Cc}/u', $password) === 1) {
            throw new InvalidEngineRequest('invalid_request', 'a SCRAM-SHA-256 password cannot contain control characters such as a newline or tab');
        }

        return ['PASSWORD' => Shell::withoutNul($password), 'ENCRYPTION' => $encryption];
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{NAME: string, GRANTS: string}
     */
    private function grantEnv(string $name, array $grants): array
    {
        $this->refuseReserved(self::RESERVED_DATABASES, ...array_map(fn (Grant $grant): string => Shell::withoutNul($grant->database), $grants));

        return ['NAME' => Shell::withoutNul($name), 'GRANTS' => json_encode(array_map(fn (Grant $grant): array => $grant->toPayload(), $grants), JSON_THROW_ON_ERROR)];
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
