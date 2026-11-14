<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Contracts\Ssh\PortProbe;
use App\Models\Server;
use App\Support\Providers\LaunchPolls;
use RuntimeException;

final readonly class AwaitServerSsh
{
    public const string LOG_NAME = 'Wait for SSH';

    public function __construct(private PortProbe $ports) {}

    public function handle(Server $server): void
    {
        $log = $server->startLog(self::LOG_NAME);
        $host = $server->address();
        $address = "{$host}:{$server->ssh_port}";

        for ($poll = 1; $poll <= LaunchPolls::MAX; $poll++) {
            if ($this->ports->isOpen($host, $server->ssh_port)) {
                $log->write("SSH answers on {$address}".PHP_EOL);
                $log->finish(0);

                return;
            }

            $log->write(LaunchPolls::checkLine($poll, "SSH on {$address} is not answering yet"));
            LaunchPolls::pause();
        }

        throw new RuntimeException('SSH did not answer within '.LaunchPolls::limit().'.');
    }
}
