<?php

declare(strict_types=1);

namespace App\Engines\Runtime;

use App\Contracts\Ssh\ServerConnection;
use App\Engines\Data\HostProfile;
use App\Engines\Data\Operation;
use App\Engines\Payload;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;
use App\Models\Server;

final readonly class Runtime
{
    private const int TIMEOUT = 900;

    private const int REMOVE_TIMEOUT = 1200;

    private const int TRANSPORT_GRACE = 30;

    private const int INVALID_REQUEST = 64;

    private const int NOT_RUNNING = 69;

    public function __construct(private ServerConnection $ssh) {}

    /**
     * @param  array<string, string>  $env
     */
    public function run(DatabaseNode $node, string $script, array $env, ?string $user, int $timeout): RuntimeRun
    {
        return RuntimeRun::fromPayload($this->send($node, 'runtime run', ['script' => $script, 'env' => (object) $env, 'user' => $user, 'timeoutSeconds' => $timeout], $timeout + self::TRANSPORT_GRACE));
    }

    /**
     * @param  array<string, string>  $env
     */
    public function startTask(DatabaseNode $node, string $key, string $script, array $env, ?string $user): Operation
    {
        return Operation::fromPayload($this->send($node, 'task start', ['key' => $key, 'script' => $script, 'env' => (object) $env, 'user' => $user]));
    }

    public function taskStatus(DatabaseNode $node, string $key): Operation
    {
        return Operation::fromPayload($this->send($node, 'task status', ['key' => $key]));
    }

    public function taskLog(DatabaseNode $node, string $key): string
    {
        return $this->send($node, 'task log', ['key' => $key])->string('lines');
    }

    public function stopTask(DatabaseNode $node, string $key): Operation
    {
        return Operation::fromPayload($this->send($node, 'task stop', ['key' => $key]));
    }

    public function getFile(DatabaseNode $node, string $path): ?string
    {
        $file = $this->send($node, 'file get', ['path' => $path]);

        if (! $file->bool('exists')) {
            return null;
        }

        return base64_decode($file->string('contentBase64'), true) ?: '';
    }

    public function putFile(DatabaseNode $node, string $path, string $content, int $mode, ?string $owner): void
    {
        $this->send($node, 'file put', ['path' => $path, 'contentBase64' => base64_encode($content), 'mode' => $mode, 'owner' => $owner]);
    }

    public function readLogs(DatabaseNode $node, ?string $unit, int $lines): LogLines
    {
        return LogLines::fromPayload($this->send($node, 'logs read', ['unit' => $unit, 'lines' => $lines]));
    }

    public function engineInfo(DatabaseNode $node): EngineInfo
    {
        return EngineInfo::fromPayload($this->send($node, 'engine info', []));
    }

    public function deploy(DatabaseNode $node, string $image, HostProfile $profile): string
    {
        return $this->send($node, 'host deploy', ['image' => $image, 'databaseId' => $node->database_id, 'nodeId' => $node->id, 'hostProfile' => $profile->toPayload()])->string('engine');
    }

    public function remove(DatabaseNode $node, bool $erase): void
    {
        $this->send($node, 'host remove', ['databaseId' => $node->database_id, 'erase' => $erase], self::REMOVE_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(DatabaseNode $node, string $command, array $payload, int $timeout = self::TIMEOUT): Payload
    {
        $json = json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $output = '';
        $exitCode = $this->ssh->run($node->server, sprintf("printf '%%s' '%s' | base64 -d | databasify %s 2>/dev/null\n", base64_encode($json), $command), function (string $chunk) use (&$output): void {
            $output .= $chunk;
        }, $timeout);

        return $this->payloadOf($output, $exitCode, $node->server);
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
}
