<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\Flow;
use App\Exceptions\Engines\StepNotReady;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use App\Models\Server;
use RuntimeException;
use Throwable;

final readonly class RebootNodeFlow implements Flow
{
    private const int BOOT_ID_TIMEOUT_SECONDS = 30;

    private const int COME_BACK_MINUTES = 15;

    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Reboot servers';
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [
                Step::prepare("note the boot of {$node->server->name}", $this->steps->quick(fn (DatabaseFlow $flow) => $this->noteBootOf($flow, $node))),
                Step::prepare("reboot {$node->server->name}", $this->steps->quick(fn () => $this->reboot($node))),
                Step::prepare("wait for {$node->server->name} to boot", $this->steps->quick(fn (DatabaseFlow $flow) => $this->ensureRebooted($flow, $node))),
                ...$this->rolling->backOnline($flow, $node),
            ]),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    private function noteBootOf(DatabaseFlow $flow, DatabaseNode $node): void
    {
        $flow->update(['context' => $flow->context->withBootId($node->id, $this->bootIdOf($node->server))]);
    }

    private function reboot(DatabaseNode $node): void
    {
        $node->server->ssh($node->database)->exec('ssh.servers.os.reboot', log: "{$node->server->name}: rebooting");
    }

    private function ensureRebooted(DatabaseFlow $flow, DatabaseNode $node): void
    {
        if ($flow->updated_at?->addMinutes(self::COME_BACK_MINUTES)->isPast() === true) {
            throw new RuntimeException("{$node->server->name} did not come back within ".self::COME_BACK_MINUTES.' minutes of the reboot.');
        }

        try {
            $bootId = $this->bootIdOf($node->server);
        } catch (Throwable) {
            throw new StepNotReady;
        }

        if ($bootId === ($flow->context->bootIds[$node->id] ?? '')) {
            throw new StepNotReady;
        }
    }

    private function bootIdOf(Server $server): string
    {
        return trim($server->ssh()->exec('ssh.servers.os.boot-id', timeout: self::BOOT_ID_TIMEOUT_SECONDS));
    }
}
