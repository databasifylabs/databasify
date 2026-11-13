<?php

declare(strict_types=1);

namespace App\Engines\Drivers;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\EngineScripts;
use App\Engines\Contracts\Accounts;
use App\Engines\Contracts\Archive;
use App\Engines\Contracts\EngineDriver;
use App\Engines\Contracts\Migration;
use App\Engines\Contracts\Pools;
use App\Engines\Data\Operation;
use App\Engines\Payload;
use App\Engines\Runtime\Runtime;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\NotPrimary;
use App\Models\DatabaseNode;
use Closure;

abstract readonly class AbstractEngineDriver implements EngineDriver
{
    private const string PHASE = 'DATABASIFY_PHASE ';

    final public function __construct(
        public DatabaseNode $node,
        public bool $followsPrimary = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function run(string $script, array $data = [], array $env = [], ?string $user = null, int $timeout = 120): void
    {
        $this->onPrimary(fn (DatabaseNode $node) => $this->scripts()->run($node, $script, $data, $user, $timeout, $this->logNameOf($node, $script), $env));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function read(string $script, array $data = [], array $env = [], ?string $user = null, int $timeout = 120): Payload
    {
        return $this->onPrimary(fn (DatabaseNode $node): Payload => $this->scripts()->run($node, $script, $data, $user, $timeout, null, $env)->json());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function task(string $key, string $script, array $data = [], array $env = [], ?string $user = null): Operation
    {
        return $this->scripts()->start($this->node, $key, $script, $data, $user, $env);
    }

    public function taskStatus(string $key): Operation
    {
        $operation = $this->runtime()->taskStatus($this->node, $key);
        $phases = array_filter(explode("\n", $this->runtime()->taskLog($this->node, $key)), fn (string $line): bool => str_starts_with($line, self::PHASE));

        if ($phases === []) {
            return $operation;
        }

        return new Operation($operation->key, $operation->kind, $operation->state, substr((string) end($phases), strlen(self::PHASE)), $operation->progress, $operation->error);
    }

    public function stopTask(string $key): Operation
    {
        return $this->runtime()->stopTask($this->node, $key);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function script(string $script, array $data = []): string
    {
        return resolve(ScriptView::class)->render($this->node, $script, $data);
    }

    public function putFile(string $path, string $content, int $mode, ?string $owner = null): void
    {
        $this->runtime()->putFile($this->node, $path, $content, $mode, $owner);
    }

    public function getFile(string $path): ?string
    {
        return $this->runtime()->getFile($this->node, $path);
    }

    public function accounts(): Accounts
    {
        throw EngineUnsupported::command('user create');
    }

    public function archive(): Archive
    {
        throw EngineUnsupported::command('archive status');
    }

    public function migration(): Migration
    {
        throw EngineUnsupported::command('migration check');
    }

    public function pools(): Pools
    {
        throw EngineUnsupported::command('pool list');
    }

    /**
     * @template TResult
     *
     * @param  Closure(DatabaseNode): TResult  $work
     * @return TResult
     */
    private function onPrimary(Closure $work): mixed
    {
        try {
            return $work($this->node);
        } catch (NotPrimary $notPrimary) {
            throw_unless($this->followsPrimary, $notPrimary);
            resolve(RefreshDatabaseStatus::class)->handle($this->node->database);

            return $work($this->node->database->refresh()->primaryOrSeed());
        }
    }

    private function logNameOf(DatabaseNode $node, string $script): string
    {
        return "{$node->server->name}: ".str_replace('-', ' ', basename($script));
    }

    private function scripts(): EngineScripts
    {
        return resolve(EngineScripts::class);
    }

    private function runtime(): Runtime
    {
        return resolve(Runtime::class);
    }
}
