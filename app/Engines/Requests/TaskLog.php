<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\TaskLog as TaskLogReply;

/**
 * @implements EngineRequest<TaskLogReply>
 */
final readonly class TaskLog implements EngineRequest
{
    public function __construct(
        public string $key,
    ) {}

    public function command(): string
    {
        return 'task log';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key];
    }

    public function reply(): string
    {
        return TaskLogReply::class;
    }
}
