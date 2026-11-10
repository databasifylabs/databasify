<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Ssh\LogGroup;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Models\ServerLog;
use Throwable;

final readonly class EngineClient
{
    public const int TIMEOUT = 900;

    private const int INVALID_REQUEST = 64;

    private const int NOT_RUNNING = 69;

    public function __construct(private ServerConnection $connection) {}

    /**
     * @template TReply of EngineReply
     *
     * @param  EngineRequest<TReply>  $request
     * @return TReply
     */
    public function send(DatabaseNode $node, EngineRequest $request, ?string $log = null, ?int $timeout = null): EngineReply
    {
        $server = $node->server;
        $serverLog = $log === null ? null : $this->logOn($node, $log);
        $output = '';

        try {
            $exitCode = $this->connection->run($server, $this->scriptFor($request), function (string $chunk) use (&$output, $serverLog): void {
                $output .= $chunk;
                $serverLog?->write($chunk);
            }, $timeout ?? self::TIMEOUT);
        } catch (Throwable $exception) {
            $serverLog?->fail($exception->getMessage());

            throw $exception;
        }

        $serverLog?->finish($exitCode);

        return $request->reply()::fromPayload($this->payloadOf($output, $exitCode, $server));
    }

    /**
     * @param  EngineRequest<EngineReply>  $request
     */
    private function scriptFor(EngineRequest $request): string
    {
        $json = json_encode((object) $request->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return sprintf("printf '%%s' '%s' | base64 -d | databasify %s 2>/dev/null\n", base64_encode($json), $request->command());
    }

    private function payloadOf(string $output, int $exitCode, Server $server): Payload
    {
        if ($exitCode === self::NOT_RUNNING) {
            throw new EngineNotRunning('not_running', "No engine container is running on {$server->name}.");
        }

        $payload = Payload::decode($output);

        if ($exitCode === 0) {
            return $payload;
        }

        if ($payload->has('error')) {
            $error = $payload->object('error');

            throw EngineError::fromReply($error->string('code'), $error->string('message'));
        }

        if ($exitCode === self::INVALID_REQUEST) {
            throw new InvalidEngineRequest('invalid_request', "The engine on {$server->name} refused the request.");
        }

        throw new EngineError('internal', "The engine on {$server->name} failed with exit code {$exitCode}.");
    }

    private function logOn(DatabaseNode $node, string $name): ServerLog
    {
        $group = LogGroup::fromContext();

        return $node->server->serverLogs()->create(['name' => $name, 'database_id' => $node->database_id, 'group_id' => $group?->id, 'group_name' => $group?->name]);
    }
}
