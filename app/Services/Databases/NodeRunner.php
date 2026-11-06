<?php

declare(strict_types=1);

namespace App\Services\Databases;

use App\DTOs\Concurrency\TaskFailure;
use App\DTOs\Ssh\LogGroup;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;
use App\Services\Ssh\SshSessions;
use App\Support\Concurrency\Packed;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final readonly class NodeRunner
{
    private const int CONCURRENCY = 50;

    private const int GROUP_SHARE = 4;

    public function __construct(private SshSessions $sessions) {}

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  Closure(DatabaseNode): mixed  $step
     */
    public function each(Collection $nodes, Closure $step, string $groupName, ?int $timeout = null, ?int $concurrency = null): void
    {
        $this->run($nodes->mapWithKeys(fn (DatabaseNode $node): array => [$node->id => $this->stepOn($node, $step)])->all(), $groupName, $timeout, $concurrency);
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  Closure(DatabaseNode): mixed  $step
     */
    public function inGroupsOf(Collection $nodes, Closure $step, string $groupName, ?int $timeout = null): void
    {
        $nodes->chunk(max(1, intdiv($nodes->count(), self::GROUP_SHARE)))
            ->each(fn (Collection $group) => $this->each($group->values(), $step, $groupName, $timeout));
    }

    /**
     * @param  array<array-key, Closure(): mixed>  $tasks
     */
    public function run(array $tasks, string $groupName, ?int $timeout = null, ?int $concurrency = null): void
    {
        if ($tasks === []) {
            return;
        }

        if (count($tasks) === 1) {
            reset($tasks)();

            return;
        }

        LogGroup::named($groupName)->toContext();

        try {
            $failure = collect($tasks)
                ->map(fn (Closure $task): Closure => $this->guarded($task))
                ->chunk($concurrency ?? self::CONCURRENCY)
                ->flatMap(fn (Collection $chunk): array => Concurrency::run($chunk->all(), $timeout ?? Ssh::LONG_TIMEOUT))
                ->map(Packed::open(...))
                ->first(fn (mixed $result): bool => $result instanceof TaskFailure);
        } finally {
            LogGroup::forget();
        }

        if ($failure instanceof TaskFailure) {
            throw $failure->toException();
        }
    }

    /**
     * @template T of object
     *
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  Closure(DatabaseNode): T  $read
     * @param  class-string<T>  $type
     * @return Collection<int, T>
     */
    public function map(Collection $nodes, Closure $read, string $type, ?int $concurrency = null): Collection
    {
        $values = $nodes->mapWithKeys(fn (DatabaseNode $node): array => [$node->id => $this->readOf($node, $read)])
            ->chunk($concurrency ?? self::CONCURRENCY)
            ->flatMap(fn (Collection $chunk): array => Concurrency::run($chunk->all(), Ssh::LONG_TIMEOUT))
            ->map(Packed::open(...));
        $failure = $values->first(fn (mixed $value): bool => $value instanceof TaskFailure);

        if ($failure instanceof TaskFailure) {
            throw $failure->toException();
        }

        return $nodes->map(fn (DatabaseNode $node): mixed => $values->get($node->id))
            ->filter(fn (mixed $value): bool => $value instanceof $type)
            ->values();
    }

    public function reconnectUnless(int|false $parent): void
    {
        if (getmypid() === $parent) {
            return;
        }

        $this->sessions->abandon();
        collect(DB::getConnections())->keys()->each(fn (string $name) => DB::purge($name));
        Redis::purge();
    }

    /**
     * @param  Closure(DatabaseNode): mixed  $step
     * @return Closure(): mixed
     */
    private function stepOn(DatabaseNode $node, Closure $step): Closure
    {
        return fn (): mixed => $step($node);
    }

    /**
     * @param  Closure(DatabaseNode): mixed  $read
     * @return Closure(): string
     */
    private function readOf(DatabaseNode $node, Closure $read): Closure
    {
        $parent = getmypid();

        return function () use ($node, $read, $parent): string {
            $this->reconnectUnless($parent);

            try {
                return Packed::of($read($node));
            } catch (Throwable $exception) {
                return Packed::of(TaskFailure::of($exception));
            }
        };
    }

    /**
     * @param  Closure(): mixed  $task
     * @return Closure(): string
     */
    private function guarded(Closure $task): Closure
    {
        $parent = getmypid();

        return function () use ($task, $parent): string {
            $this->reconnectUnless($parent);

            try {
                $task();

                return Packed::of(null);
            } catch (Throwable $exception) {
                return Packed::of(TaskFailure::of($exception));
            }
        };
    }
}
