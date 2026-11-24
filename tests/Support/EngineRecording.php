<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\DatabaseNode;
use Closure;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

final class EngineRecording
{
    /** @var array<string, mixed> */
    private array $replies = [];

    /** @var list<array{call: string, node: DatabaseNode, args: list<mixed>}> */
    private array $calls = [];

    public function on(string $call, mixed $reply): self
    {
        $this->replies[$call] = $reply;

        return $this;
    }

    /**
     * @param  list<mixed>  $args
     */
    public function answer(string $call, DatabaseNode $node, array $args, bool $returnsValue): mixed
    {
        $this->calls[] = ['call' => $call, 'node' => $node, 'args' => $args];

        if (! array_key_exists($call, $this->replies)) {
            return $returnsValue ? throw new LogicException("The engine read {$call} on {$node->server->name} has no scripted reply.") : null;
        }

        $reply = $this->replies[$call] instanceof Closure ? ($this->replies[$call])($node, ...$args) : $this->replies[$call];

        return $reply instanceof Throwable ? throw $reply : $reply;
    }

    /**
     * @return Collection<int, array{call: string, node: DatabaseNode, args: list<mixed>}>
     */
    public function callsOf(string $call): Collection
    {
        return collect($this->calls)->where('call', $call)->values();
    }

    public function called(string $call, ?DatabaseNode $on = null): bool
    {
        return $this->callsOf($call)->contains(fn (array $recorded): bool => $on === null || $recorded['node']->is($on));
    }

    public function changedNothing(): bool
    {
        return collect($this->calls)->doesntContain(fn (array $recorded): bool => in_array($recorded['call'], self::WRITES, true));
    }

    private const array WRITES = [
        'nodes.configure', 'nodes.start', 'nodes.restart', 'nodes.wake', 'nodes.reset', 'nodes.bootstrap', 'nodes.installTls',
        'cluster.enable', 'cluster.disable', 'cluster.failover', 'cluster.forceQuorum', 'cluster.membership', 'cluster.rejoin', 'cluster.forget',
        'config.apply', 'config.sync',
        'accounts.createDatabase', 'accounts.dropDatabase', 'accounts.createUser', 'accounts.setPassword', 'accounts.grant', 'accounts.dropUser',
        'archive.restore', 'migration.start', 'migration.stop', 'pools.create', 'pools.update', 'pools.delete',
        'extensions.enable', 'extensions.update', 'extensions.disable', 'extensions.allowInstalls', 'extensions.installPackages', 'extensions.removePackages',
    ];
}
