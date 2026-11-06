<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ServerLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ServerLogOutputReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ServerLog $log, public string $chunk, public int $offset) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("server-logs.{$this->log->id}");
    }

    /**
     * @return array{chunk: string, offset: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['chunk' => $this->chunk, 'offset' => $this->offset, 'status' => $this->log->status->value];
    }
}
