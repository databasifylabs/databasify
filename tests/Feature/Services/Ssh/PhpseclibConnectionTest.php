<?php

declare(strict_types=1);

use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use App\Services\Ssh\PhpseclibConnection;
use Illuminate\Support\Sleep;

function closedLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);

    return $port;
}

it('retries a connection that cannot be made twice, then fails as a connection failure', function () {
    Sleep::fake();
    $server = Server::factory()->create(['public_ip' => '127.0.0.1', 'ssh_port' => closedLocalPort()]);

    expect(fn () => resolve(PhpseclibConnection::class)->run($server, 'true', fn (string $chunk) => null))
        ->toThrow(SshConnectionFailed::class, "Could not connect to 127.0.0.1:{$server->ssh_port}");

    Sleep::assertSleptTimes(2);
});
