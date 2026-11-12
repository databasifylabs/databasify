<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

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
use App\Engines\Scripts\Shell;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Accounts
{
    public function __construct(private FamilyScripts $scripts) {}

    public function list(DatabaseNode $node): AccountList
    {
        $engine = FamilyScripts::engineOf($node);
        $reply = $this->scripts->run($node, 'mysql-family/accounts/list')->json();

        return new AccountList($this->withoutReserved($reply->strings('users'), $engine->reservedUsers), $this->withoutReserved($reply->strings('databases'), $engine->reservedDatabases));
    }

    public function createDatabase(DatabaseNode $node, DatabaseCreate $request): Done
    {
        $this->refuseReserved(FamilyScripts::engineOf($node)->reservedDatabases, $request->name);
        $this->scripts->run($node, 'mysql-family/accounts/create-database', ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function dropDatabase(DatabaseNode $node, DatabaseDrop $request): Done
    {
        $this->refuseReserved(FamilyScripts::engineOf($node)->reservedDatabases, $request->name);
        $this->scripts->run($node, 'mysql-family/accounts/drop-database', ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function createUser(DatabaseNode $node, UserCreate $request): UserCreateReply
    {
        $env = [...$this->identifiedEnv($node, $request->password, $request->encryption), ...$this->grantEnv($node, $request->name, $request->grants)];

        return new UserCreateReply($this->scripts->run($node, 'mysql-family/accounts/create-user', $env)->json()->bool('created'));
    }

    public function setPassword(DatabaseNode $node, UserSetPassword $request): Done
    {
        $env = $this->identifiedEnv($node, $request->password, $request->encryption);
        $this->refuseReserved(FamilyScripts::engineOf($node)->reservedUsers, $request->name);
        $this->scripts->run($node, 'mysql-family/accounts/set-password', [...$env, 'NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function grant(DatabaseNode $node, UserGrant $request): Done
    {
        $this->scripts->run($node, 'mysql-family/accounts/grant', [...$this->grantEnv($node, $request->name, $request->grants), 'REPLICATION' => $request->replication ? 'true' : 'false']);

        return new Done;
    }

    public function dropUser(DatabaseNode $node, UserDrop $request): Done
    {
        $this->refuseReserved(FamilyScripts::engineOf($node)->reservedUsers, $request->name);
        $this->scripts->run($node, 'mysql-family/accounts/drop-user', ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    /**
     * @return array{PASSWORD: string, IDENTIFIED_BY: string, IDENTIFIED_CLOSE: string}
     */
    private function identifiedEnv(DatabaseNode $node, string $password, ?string $requested): array
    {
        $engine = FamilyScripts::engineOf($node);
        $encryption = $requested ?? $engine->defaultEncryption;

        if (! in_array($encryption, $engine->encryptionsOn($node->database->version_line), true)) {
            throw new InvalidEngineRequest('invalid_request', 'password encryption '.json_encode($encryption, JSON_THROW_ON_ERROR).' is not offered');
        }

        [$by, $close] = $engine->identifiedWith($encryption);

        return ['PASSWORD' => Shell::withoutNul($password), 'IDENTIFIED_BY' => $by, 'IDENTIFIED_CLOSE' => $close];
    }

    /**
     * @param  list<Grant>  $grants
     * @return array{NAME: string, GRANTS: string, RESERVED_DATABASES: string}
     */
    private function grantEnv(DatabaseNode $node, string $name, array $grants): array
    {
        $engine = FamilyScripts::engineOf($node);
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
