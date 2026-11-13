<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\DTOs\Databases\NodeStatus;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Database;
use App\Models\DatabaseNode;
use Throwable;

final readonly class ReadNodeStatus
{
    public function handle(Database $database, DatabaseNode $node, ?string $log = null): NodeStatus
    {
        try {
            return $node->driver()->nodes()->status();
        } catch (EngineNotRunning) {
            return NodeStatus::stopped();
        } catch (EngineError|SshConnectionFailed|SshAccessDenied) {
            return NodeStatus::unreachable();
        } catch (Throwable $exception) {
            report($exception);

            return NodeStatus::unreachable();
        }
    }
}
