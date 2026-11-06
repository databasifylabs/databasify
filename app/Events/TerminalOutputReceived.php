<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class TerminalOutputReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $terminal, public string $output, public bool $closed = false) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("terminals.{$this->terminal}");
    }

    /**
     * @return array{data: string, closed: bool}
     */
    public function broadcastWith(): array
    {
        return ['data' => base64_encode($this->output), 'closed' => $this->closed];
    }
}
