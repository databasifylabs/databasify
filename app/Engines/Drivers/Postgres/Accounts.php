<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Grant;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Engines\Responses\AccountList;
use App\Engines\Responses\Done;
use App\Engines\Responses\UserCreate as UserCreateReply;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Accounts
{
    public const array RESERVED_USERS = ['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'];

    public const array RESERVED_DATABASES = ['postgres', 'template0', 'template1'];

    private const string RESERVED_ROLE_PREFIX = 'pg_';

    public function __construct(private EngineScripts $scripts) {}

    public function list(DatabaseNode $node): AccountList
    {
        $reply = $this->scripts->run($node, 'postgres/accounts/list', $this->dataFor($node))->json();

        return new AccountList($this->withoutReserved($reply->strings('users'), self::RESERVED_USERS), $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES));
    }

    public function createDatabase(DatabaseNode $node, DatabaseCreate $request): Done
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $request->name);
        $this->scripts->run($node, 'postgres/accounts/create-database', $this->dataFor($node), env: ['NAME' => $request->name]);

        return new Done;
    }

    public function dropDatabase(DatabaseNode $node, DatabaseDrop $request): Done
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $request->name);
        $this->scripts->run($node, 'postgres/accounts/drop-database', $this->dataFor($node), env: ['NAME' => $request->name]);

        return new Done;
    }

    public function createUser(DatabaseNode $node, UserCreate $request): UserCreateReply
    {
        $this->rejectEncryption($request->encryption);
        $this->refuseReservedAccount($request->name, $request->grants);
        $reply = $this->scripts->run($node, 'postgres/accounts/create-user', $this->dataFor($node), env: [...$this->grantEnv($request->name, $request->grants), 'PASSWORD' => $request->password]);

        return new UserCreateReply($reply->json()->bool('created'));
    }

    public function setPassword(DatabaseNode $node, UserSetPassword $request): Done
    {
        $this->rejectEncryption($request->encryption);
        $this->refuseReservedAccount($request->name, []);
        $this->scripts->run($node, 'postgres/accounts/set-password', $this->dataFor($node), env: ['NAME' => $request->name, 'PASSWORD' => $request->password]);

        return new Done;
    }

    public function grant(DatabaseNode $node, UserGrant $request): Done
    {
        $this->refuseReservedAccount($request->name, $request->grants);
        $this->scripts->run($node, 'postgres/accounts/grant', $this->dataFor($node), env: [...$this->grantEnv($request->name, $request->grants), 'REPLICATION' => $request->replication ? 'true' : 'false']);

        return new Done;
    }

    public function dropUser(DatabaseNode $node, UserDrop $request): Done
    {
        $this->refuseReservedAccount($request->name, []);
        $this->scripts->run($node, 'postgres/accounts/drop-user', $this->dataFor($node), env: ['NAME' => $request->name]);

        return new Done;
    }

    /**
     * @return array{port: int}
     */
    public function dataFor(DatabaseNode $node): array
    {
        return ['port' => $node->database->clientPort()];
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
