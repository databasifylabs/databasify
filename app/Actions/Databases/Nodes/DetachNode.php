<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Databases\WorkLeftUndone;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use App\Support\Messages\Sentence;
use Closure;
use Throwable;

final readonly class DetachNode
{
    public function __construct(
        private StopDatabaseNode $stop,
        private NodeRunner $nodeRunner,
        private UpdateDatabaseMembership $membership,
        private RemoveNodeFirewall $firewall,
        private ApplyNodeFirewall $remainingFirewall,
        private ClearNodeDns $dns,
        private UnclusterDatabase $uncluster,
    ) {}

    public function handle(Database $database, DatabaseNode $node, bool $force = false): void
    {
        $remaining = $database->nodesOtherThan($node);
        $unforgotten = $this->failureOf($force, fn () => $this->forgetOnPrimaryAmong($remaining, $database, $node));
        $unerased = $this->failureOf($force, fn () => $this->stopAndErase($database, $node));
        $this->forget($database, $node);
        $peerFailures = $database->ignoringStop(fn (): array => $this->peerFailuresAfterForgetting($database, $remaining, $force));

        $this->warnIfUnerased($database, $node, $unerased);
        $this->failIfLeftUndone($database, $node, array_values(array_filter([$unforgotten, ...$peerFailures], is_string(...))));
    }

    /**
     * @return list<string|null>
     */
    private function peerFailuresAfterForgetting(Database $database, DatabaseNodeCollection $remaining, bool $force): array
    {
        return [
            $this->failureOf($force, fn () => $this->membership->handle($database)),
            $this->failureOf(false, fn () => $this->closePeerPortsTo($database, $remaining)),
            $this->failureOf($force, fn () => $this->resetSentinelsOn($remaining, $database)),
            $this->failureOf($force, fn () => $this->uncluster->handle($database)),
        ];
    }

    private function failureOf(bool $tolerated, Closure $step): ?string
    {
        try {
            $step();

            return null;
        } catch (OperationStopped $stopped) {
            throw $stopped;
        } catch (Throwable $exception) {
            throw_unless($tolerated, $exception);
            report($exception);

            return $exception->getMessage();
        }
    }

    private function warnIfUnerased(Database $database, DatabaseNode $node, ?string $failure): void
    {
        if ($failure === null) {
            return;
        }

        $this->logRemovalFailureOf(
            $database,
            $node,
            "{$node->server->name} was removed; its server could not be reached, so its data was not erased: ".Sentence::of($failure)." Wipe or destroy {$node->server->name}.",
        );
    }

    /**
     * @param  list<string>  $failures
     */
    private function failIfLeftUndone(Database $database, DatabaseNode $node, array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $message = "{$node->server->name} was removed, but ".implode(' ', array_map(Sentence::of(...), $failures))." Redeploy {$database->name} to finish.";
        $this->logRemovalFailureOf($database, $node, $message);

        throw new WorkLeftUndone($message);
    }

    private function logRemovalFailureOf(Database $database, DatabaseNode $node, string $message): void
    {
        $node->server->logFailure("{$node->server->name}: removing it from {$database->name}", $message, $database);
    }

    private function forgetOnPrimaryAmong(DatabaseNodeCollection $remaining, Database $database, DatabaseNode $node): void
    {
        $primary = $remaining->primary() ?? $remaining->first();

        if ($primary === null) {
            return;
        }

        $database->provider()->cluster()->forget($primary, $node);
    }

    private function closePeerPortsTo(Database $database, DatabaseNodeCollection $remaining): void
    {
        $sources = $database->trustedCidrs();
        $this->nodeRunner->each(
            $remaining,
            fn (DatabaseNode $member) => $this->closePeerPortsOn($database, $member, $sources),
            "closing peer ports on {$remaining->count()} nodes",
        );
    }

    /**
     * @param  array<int, string>  $sources
     */
    private function closePeerPortsOn(Database $database, DatabaseNode $member, array $sources): void
    {
        rescue(fn () => $this->remainingFirewall->handle($database, $member, $sources));
    }

    private function forget(Database $database, DatabaseNode $node): void
    {
        $this->clearDnsOf($database, $node);
        $node->delete();
        $database->forgetNodeCertificates($node);
    }

    private function clearDnsOf(Database $database, DatabaseNode $node): void
    {
        try {
            $this->dns->handle($node);
        } catch (Throwable $exception) {
            $node->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records of {$node->server->name}, delete them by hand: {$exception->getMessage()}", $database);
        }
    }

    private function stopAndErase(Database $database, DatabaseNode $node): void
    {
        $this->stop->handle($database, $node, true, "{$node->server->name}: stopping and erasing its copy of the data");
        $this->firewall->handle($database, $node);
    }

    private function resetSentinelsOn(DatabaseNodeCollection $remaining, Database $database): void
    {
        $this->nodeRunner->each(
            $remaining,
            fn (DatabaseNode $member) => $database->provider()->nodes()->reset($member, $remaining->count() - 1),
            "resetting cluster peers on {$remaining->count()} nodes",
        );
    }
}
