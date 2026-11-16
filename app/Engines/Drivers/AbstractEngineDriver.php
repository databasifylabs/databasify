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
use App\Engines\Contracts\SqlQuoting;
use App\Engines\Data\Operation;
use App\Engines\Payload;
use App\Engines\Runtime\Runtime;
use App\Engines\Scripts\ScriptView;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\NotPrimary;
use App\Models\DatabaseNode;
use Closure;
use Illuminate\Support\Str;

abstract readonly class AbstractEngineDriver implements EngineDriver
{
    final public function __construct(
        public DatabaseNode $node,
        public bool $followsPrimary = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     * @param  int<1, 3600>  $timeout
     */
    public function run(string $script, array $data = [], array $env = [], ?string $user = null, int $timeout = 120): void
    {
        $this->onPrimary(fn (DatabaseNode $node) => $this->scripts()->run($node, $script, $this->withViewData($data), $user, $timeout, $this->logNameOf($node, $script), $env));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     * @param  int<1, 3600>  $timeout
     */
    public function read(string $script, array $data = [], array $env = [], ?string $user = null, int $timeout = 120): Payload
    {
        return $this->onPrimary(fn (DatabaseNode $node): Payload => $this->scripts()->run($node, $script, $this->withViewData($data), $user, $timeout, null, $env)->json());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int<1, 3600>  $timeout
     */
    public function query(string $view, array $data = [], int $timeout = 120): void
    {
        $this->onPrimary(fn (DatabaseNode $node) => $this->scripts()->run($node, $this->queryRunner(), $this->queryData($node, $view, $data, false), null, $timeout, $this->logNameOf($node, $view)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int<1, 3600>  $timeout
     */
    public function queryJson(string $view, array $data = [], int $timeout = 120): Payload
    {
        return $this->onPrimary(fn (DatabaseNode $node): Payload => $this->scripts()->run($node, $this->queryRunner(), $this->queryData($node, $view, $data, true), null, $timeout)->json());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function task(string $key, string $script, array $data = [], array $env = [], ?string $user = null): Operation
    {
        return $this->scripts()->start($this->node, $key, $script, $this->withViewData($data), $user, $env);
    }

    public function taskStatus(string $key): Operation
    {
        $operation = $this->runtime()->taskStatus($this->node, $key);

        if ($operation->state === OperationState::Running) {
            return $operation;
        }

        $output = collect(explode("\n", rescue(fn (): string => $this->runtime()->taskLog($this->node, $key), '', report: false)))
            ->map(fn (string $line): string => (string) preg_replace('/^\d{4}-\d{2}-\d{2}T\S+Z /', '', $line))
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, 'databasify: ') || str_starts_with($line, 'DATABASIFY_'))
            ->map(fn (string $line): string => $line.PHP_EOL)
            ->implode('');

        return new Operation($operation->key, $operation->kind, $operation->state, $operation->phase, $operation->progress, $operation->error, $output);
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
        return resolve(ScriptView::class)->render($this->node, $script, $this->withViewData($data));
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

    protected function queryRunner(): string
    {
        throw EngineUnsupported::command('query');
    }

    protected function sqlQuoting(): ?SqlQuoting
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [];
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function queryData(DatabaseNode $node, string $view, array $data, bool $json): array
    {
        $merged = $this->withViewData($data);

        return [...$merged, 'view' => $view, 'query' => base64_encode(resolve(ScriptView::class)->render($node, $view, $merged)), 'json' => $json];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withViewData(array $data): array
    {
        return [...$this->viewData(), 'sql' => $this->sqlQuoting(), ...$data];
    }

    private function logNameOf(DatabaseNode $node, string $script): string
    {
        return "{$node->server->name}: ".str_replace('-', ' ', Str::afterLast(Str::afterLast($script, '::'), '.'));
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
