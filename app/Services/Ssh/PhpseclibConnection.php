<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Contracts\Ssh\ServerConnection;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use App\Support\Ssh\Fingerprint;
use Closure;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\TimeoutException;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;
use Throwable;

final readonly class PhpseclibConnection implements ServerConnection
{
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    private const int RUN_TIMEOUT_SECONDS = 3600;

    private const int KEEP_ALIVE_SECONDS = 15;

    private const string SCRIPT_DIRECTORY = '.databasify';

    private const int CONNECT_ATTEMPTS = 3;

    private const int RETRY_DELAY_MILLISECONDS = 3000;

    private const float SHELL_POLL_SECONDS = 0.05;

    private const string TERMINAL = 'xterm-256color';

    public function __construct(
        private AppSshKey $appKey,
        private SshSessions $sessions,
    ) {}

    public function run(Server $server, string $script, Closure $onOutput, int $timeout = self::RUN_TIMEOUT_SECONDS): int
    {
        [$ssh, $path] = $this->retrying(fn (): array => $this->connectAndUpload($server, $script));
        $ssh->setTimeout($timeout);
        $ssh->setKeepAlive(self::KEEP_ALIVE_SECONDS);
        $ssh->exec($this->commandFor($server, $path), function (string $chunk) use ($onOutput): void {
            $onOutput($chunk);
        });
        $timedOut = $ssh->isTimeout();
        $exitCode = $ssh->getExitStatus();

        if ($timedOut) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "No result from {$server->address()} within {$timeout} seconds, the connection was lost or the script hung.");
        }

        if (! is_int($exitCode)) {
            $this->sessions->forget($server);

            return 1;
        }

        return $exitCode;
    }

    public function shell(Server $server, int $columns, int $rows, Closure $onOutput, Closure $nextInput): void
    {
        $ssh = $this->retrying(fn (): SFTP => $this->connectTo($server));
        $ssh->setTerminal(self::TERMINAL);
        $ssh->setWindowSize($columns, $rows);
        $ssh->setKeepAlive(self::KEEP_ALIVE_SECONDS);
        $ssh->openShell();
        $ssh->setTimeout(self::SHELL_POLL_SECONDS);

        if ($server->ssh_user !== 'root') {
            $ssh->write("exec sudo -i\n");
        }

        while ($ssh->isShellOpen() && ($input = $nextInput()) !== null) {
            if ($input !== '') {
                $ssh->write($input);
            }

            $output = $ssh->read();

            if (is_string($output) && $output !== '') {
                $onOutput($output);
            }
        }

        $ssh->disconnect();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $connect
     * @return TResult
     */
    private function retrying(Closure $connect): mixed
    {
        return retry(
            self::CONNECT_ATTEMPTS,
            $connect,
            self::RETRY_DELAY_MILLISECONDS,
            fn (Throwable $exception): bool => ! $exception instanceof SshAccessDenied,
        );
    }

    /**
     * @return array{SFTP, string}
     */
    private function connectAndUpload(Server $server, string $script): array
    {
        try {
            $ssh = $this->sessions->sessionFor($server, fn (): SFTP => $this->connectTo($server));

            return [$ssh, $this->upload($ssh, $server, $script)];
        } catch (ConnectionClosedException|TimeoutException|UnableToConnectException $exception) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "Could not connect to {$server->address()}:{$server->ssh_port}: {$exception->getMessage()}");
        } catch (Throwable $exception) {
            $this->sessions->forget($server);

            throw $exception;
        }
    }

    private function upload(SFTP $sftp, Server $server, string $script): string
    {
        $home = $sftp->realpath('.');

        if (! is_string($home)) {
            throw SshConnectionFailed::onServer($server, 'Could not find the home directory for remote scripts.');
        }

        $directory = "{$home}/".self::SCRIPT_DIRECTORY;

        if (! $sftp->is_dir($directory) && ! $sftp->mkdir($directory, 0700)) {
            throw SshConnectionFailed::onServer($server, "Could not create {$directory} for remote scripts.");
        }

        $sftp->chmod(0700, $directory);
        $path = "{$directory}/".Str::random(32).'.sh';

        if (! $sftp->put($path, $script)) {
            throw SshConnectionFailed::onServer($server, "Could not upload the script to {$path}.");
        }

        return $path;
    }

    private function connectTo(Server $server): SFTP
    {
        $ssh = new SFTP($server->address(), $server->ssh_port, self::CONNECT_TIMEOUT_SECONDS);
        $this->verifyHostKeyOf($server, $ssh);

        if ($this->loginWithKey($server, $ssh) || $this->loginWithPassword($server, $ssh)) {
            return $ssh;
        }

        throw SshAccessDenied::onServer($server, "Could not log in to {$server->ssh_user}@{$server->address()}.");
    }

    private function verifyHostKeyOf(Server $server, SFTP $ssh): void
    {
        $hostKey = $ssh->getServerPublicHostKey();

        if (! is_string($hostKey)) {
            throw SshConnectionFailed::onServer($server, "Could not reach {$server->address()}:{$server->ssh_port}.");
        }

        $fingerprint = Fingerprint::sha256($hostKey);

        if (! $server->trustsHostKey($fingerprint)) {
            throw SshAccessDenied::onServer($server, "Host key of {$server->address()} changed to {$fingerprint}, refusing to connect.");
        }
    }

    private function loginWithKey(Server $server, SFTP $ssh): bool
    {
        return $ssh->login($server->ssh_user, PublicKeyLoader::loadPrivateKey($this->appKey->get()->private_key));
    }

    private function loginWithPassword(Server $server, SFTP $ssh): bool
    {
        return $server->ssh_password !== null && $ssh->login($server->ssh_user, $server->ssh_password);
    }

    private function commandFor(Server $server, string $path): string
    {
        $script = escapeshellarg($path);
        $command = 'bash -c '.escapeshellarg("bash {$script}; status=\$?; rm -f {$script}; exit \$status");

        return $server->ssh_user === 'root' ? $command : 'sudo -n '.$command;
    }
}
