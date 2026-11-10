<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\DTOs\Databases\NodeStatus;
use App\Engines\EngineClient;
use App\Engines\Requests\NodeStatus as NodeStatusRequest;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Database;
use App\Models\DatabaseNode;
use Throwable;

final readonly class ReadNodeStatus
{
    public function __construct(private EngineClient $engine) {}

    public function handle(Database $database, DatabaseNode $node, ?string $log = null): NodeStatus
    {
        try {
            return NodeStatus::fromEngine($this->engine->send($node, new NodeStatusRequest, $log));
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
