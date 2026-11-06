<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\DTOs\Databases\NodeStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
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
            return $database->provider()->nodes()->status($node, $log);
        } catch (RemoteScriptFailed $failure) {
            return $failure->foundContainerStopped() ? NodeStatus::stopped() : NodeStatus::unreachable();
        } catch (SshConnectionFailed|SshAccessDenied) {
            return NodeStatus::unreachable();
        } catch (Throwable $exception) {
            report($exception);

            return NodeStatus::unreachable();
        }
    }
}
