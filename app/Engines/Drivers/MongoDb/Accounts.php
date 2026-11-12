<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

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
use App\Engines\Scripts\Shell;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Accounts
{
    public const array RESERVED_USERS = ['root', 'databasify_pbm', '__system'];

    public const array RESERVED_DATABASES = ['admin', 'local', 'config'];

    private const string SCRAM_SHA_256 = 'SCRAM-SHA-256';

    private const array ENCRYPTIONS = [self::SCRAM_SHA_256, 'SCRAM-SHA-1'];

    public function __construct(private EngineScripts $scripts) {}

    public function list(DatabaseNode $node): AccountList
    {
        $reply = $this->scripts->run($node, 'mongodb/accounts/list')->json();

        return new AccountList($this->withoutReserved($reply->strings('users'), self::RESERVED_USERS), $this->withoutReserved($reply->strings('databases'), self::RESERVED_DATABASES));
    }

    public function createDatabase(DatabaseNode $node, DatabaseCreate $request): Done
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $request->name);
        $this->scripts->run($node, 'mongodb/accounts/create-database', env: ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function dropDatabase(DatabaseNode $node, DatabaseDrop $request): Done
    {
        $this->refuseReserved(self::RESERVED_DATABASES, $request->name);
        $this->scripts->run($node, 'mongodb/accounts/drop-database', env: ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function createUser(DatabaseNode $node, UserCreate $request): UserCreateReply
    {
        $this->refuseReserved(self::RESERVED_USERS, $request->name);
        $env = [...$this->passwordEnv($request->password, $request->encryption), ...$this->grantEnv($request->name, $request->grants)];

        return new UserCreateReply($this->scripts->run($node, 'mongodb/accounts/create-user', env: $env)->json()->bool('created'));
    }

    public function setPassword(DatabaseNode $node, UserSetPassword $request): Done
    {
        $this->refuseReserved(self::RESERVED_USERS, $request->name);
        $this->scripts->run($node, 'mongodb/accounts/set-password', env: [...$this->passwordEnv($request->password, $request->encryption), 'NAME' => Shell::withoutNul($request->name)]);

        return new Done;
    }

    public function grant(DatabaseNode $node, UserGrant $request): Done
    {
        $this->refuseReserved(self::RESERVED_USERS, $request->name);
        $this->scripts->run($node, 'mongodb/accounts/grant', env: [...$this->grantEnv($request->name, $request->grants), 'REPLICATION' => $request->replication ? 'true' : 'false']);

        return new Done;
    }

    public function dropUser(DatabaseNode $node, UserDrop $request): Done
    {
        $this->refuseReserved(self::RESERVED_USERS, $request->name);
        $this->scripts->run($node, 'mongodb/accounts/drop-user', env: ['NAME' => Shell::withoutNul($request->name)]);

        return new Done;
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
