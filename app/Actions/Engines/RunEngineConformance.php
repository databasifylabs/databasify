<?php

declare(strict_types=1);

namespace App\Actions\Engines;

use App\DTOs\Engines\ConformanceStep;
use App\Engines\Data\Grant;
use App\Engines\EngineClient;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\NodeStatus;
use App\Engines\Requests\OperationStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Enums\Databases\GrantAccess;
use App\Exceptions\Engines\EngineNotFound;
use App\Models\Database;
use App\Models\DatabaseNode;
use Closure;
use RuntimeException;
use Throwable;

final readonly class RunEngineConformance
{
    private const string QUOTED_PASSWORD = "p'a\"s\\s wörd";

    private const string NEWLINE_PASSWORD = "line\none";

    public function __construct(private EngineClient $engine) {}

    /**
     * @return list<ConformanceStep>
     */
    public function handle(Database $database): array
    {
        $node = $database->primary() ?? $database->seed();
        $suffix = bin2hex(random_bytes(4));
        $schema = "conformance_{$suffix}";
        $user = "conformance_{$suffix}";

        return [
            $this->step('node status', fn () => $this->engine->send($node, new NodeStatus)),
            $this->step('account list', fn () => $this->engine->send($node, new AccountList)),
            $this->step('database create', fn () => $this->engine->send($node, new DatabaseCreate($schema))),
            $this->step('database create again', fn () => $this->engine->send($node, new DatabaseCreate($schema))),
            $this->step('user create', fn () => $this->expect($this->engine->send($node, new UserCreate($user, self::QUOTED_PASSWORD, [new Grant($schema, GrantAccess::Write)], null))->created, 'the user was not reported as created')),
            $this->step('user create again', fn () => $this->expect(! $this->engine->send($node, new UserCreate($user, self::QUOTED_PASSWORD, [new Grant($schema, GrantAccess::Write)], null))->created, 'the existing user was reported as created')),
            $this->step('user set-password', fn () => $this->engine->send($node, new UserSetPassword($user, self::QUOTED_PASSWORD, null))),
            $this->step('user set-password with a newline', fn () => $this->engine->send($node, new UserSetPassword($user, self::NEWLINE_PASSWORD, null))),
            $this->step('user grant', fn () => $this->engine->send($node, new UserGrant($user, [new Grant($schema, GrantAccess::Read)]))),
            $this->step('account list shows new accounts', fn () => $this->expectListed($node, $user, $schema)),
            $this->step('user drop', fn () => $this->engine->send($node, new UserDrop($user))),
            $this->step('database drop', fn () => $this->engine->send($node, new DatabaseDrop($schema))),
            $this->step('user drop again', fn () => $this->engine->send($node, new UserDrop($user))),
            $this->step('database drop again', fn () => $this->engine->send($node, new DatabaseDrop($schema))),
            $this->step('operation status unknown key', fn () => $this->expectNotFound($node)),
        ];
    }

    private function step(string $name, Closure $check): ConformanceStep
    {
        try {
            $check();
        } catch (Throwable $exception) {
            return new ConformanceStep($name, $exception->getMessage());
        }

        return new ConformanceStep($name, null);
    }

    private function expect(bool $holds, string $failure): void
    {
        if (! $holds) {
            throw new RuntimeException($failure);
        }
    }

    private function expectListed(DatabaseNode $node, string $user, string $schema): void
    {
        $accounts = $this->engine->send($node, new AccountList);

        $this->expect(in_array($user, $accounts->users, true) && in_array($schema, $accounts->databases, true), "{$user} or {$schema} is missing from the account list");
    }

    private function expectNotFound(DatabaseNode $node): void
    {
        try {
            $this->engine->send($node, new OperationStatus('conformance.missing.1'));
        } catch (EngineNotFound) {
            return;
        }

        throw new RuntimeException('an unknown operation key was not reported as not found');
    }
}
