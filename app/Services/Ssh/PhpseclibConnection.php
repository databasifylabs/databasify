<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Contracts\Ssh\ServerConnection;
use App\Engines\Scripts\Shell;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use App\Support\Ssh\Fingerprint;
use Closure;
use Illuminate\Support\Str;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Exception\ConnectionClosedException;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Exception\ServiceUnavailableException;
use phpseclib4\Exception\TimeoutException;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SSH2;
use Throwable;

final readonly class PhpseclibConnection implements ServerConnection
{
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    private const int RUN_TIMEOUT_SECONDS = 3600;

    private const int KEEP_ALIVE_SECONDS = 15;

    private const string SCRIPT_DIRECTORY = '.databasify';

    private const int CONNECT_ATTEMPTS = 3;

    private const int RETRY_DELAY_MILLISECONDS = 3000;

    private const int SHELL_POLL_SECONDS = 1;

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
        try {
            $ssh->exec($this->commandFor($server, $path), function (string $chunk) use ($onOutput): void {
                $onOutput($chunk);
            });
        } catch (TimeoutException) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "No result from {$server->address()} within {$timeout} seconds, the connection was lost or the script hung.");
        }

        $exitCode = $ssh->getExitStatus();

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

            $output = $this->pollShellOf($ssh);

            if ($output !== '') {
                $onOutput($output);
            }
        }

        $ssh->disconnect();
    }

    public function putFile(Server $server, string $localPath, string $remotePath): void
    {
        $staging = $this->retrying(function () use ($server, $localPath): string {
            [$sftp, $staging] = $this->connectAndUpload($server, '');

            try {
                $this->transferring($server, fn () => $sftp->put($staging, $localPath, SFTP::SOURCE_LOCAL_FILE));
            } catch (Throwable $failure) {
                rescue(fn () => $sftp->delete($staging), report: false);

                throw $failure;
            }

            return $staging;
        });
        $this->runOrFail($server, view('ssh.servers.files.place', ['sh' => new Shell, 'staging' => $staging, 'remotePath' => $remotePath])->render());
    }

    public function getFile(Server $server, string $remotePath, string $localPath): void
    {
        $this->retrying(function () use ($server, $remotePath, $localPath): void {
            [$sftp, $staging] = $this->connectAndUpload($server, '');
            try {
                $this->runOrFail($server, view('ssh.servers.files.stage', ['sh' => new Shell, 'remotePath' => $remotePath, 'staging' => $staging, 'user' => $server->ssh_user])->render());
                $this->transferring($server, fn () => $sftp->get($staging, $localPath));
            } finally {
                rescue(fn () => $sftp->delete($staging), report: false);
            }
        });
    }

    private function transferring(Server $server, Closure $transfer): void
    {
        try {
            $transfer();
        } catch (ConnectionClosedException|TimeoutException $exception) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "Lost the connection to {$server->address()} during a file transfer: {$exception->getMessage()}");
        }
    }

    private function runOrFail(Server $server, string $command): void
    {
        $output = '';
        $exit = $this->run($server, $command, function (string $chunk) use (&$output): void {
            $output .= $chunk;
        });

        if ($exit !== 0) {
            throw SshConnectionFailed::onServer($server, trim($output) !== '' ? trim($output) : "{$command} failed on {$server->address()}.");
        }
    }

    private function pollShellOf(SFTP $ssh): string
    {
        try {
            $output = $ssh->read('', SSH2::READ_NEXT);
        } catch (TimeoutException) {
            return '';
        }

        return is_string($output) ? $output : '';
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

            return [$ssh, $this->upload($ssh, $script)];
        } catch (ConnectionClosedException|TimeoutException|ServiceUnavailableException $exception) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "Could not connect to {$server->address()}:{$server->ssh_port}: {$exception->getMessage()}");
        } catch (FileSystemException $exception) {
            $this->sessions->forget($server);

            throw SshConnectionFailed::onServer($server, "Could not upload the remote script: {$exception->getMessage()}");
        } catch (Throwable $exception) {
            $this->sessions->forget($server);

            throw $exception;
        }
    }

    private function upload(SFTP $sftp, string $script): string
    {
        $directory = $sftp->realpath('.').'/'.self::SCRIPT_DIRECTORY;

        if (! $sftp->is_dir($directory)) {
            $sftp->mkdir($directory, 0700);
        }

        $sftp->chmod($directory, 0700);
        $path = "{$directory}/".Str::random(32).'.sh';
        $sftp->put($path, $script);

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
        return trim(view('ssh.servers.run-script', ['sh' => new Shell, 'path' => $path, 'sudo' => $server->ssh_user !== 'root'])->render());
    }
}
