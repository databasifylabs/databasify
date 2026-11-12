<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Operation;

/**
 * @implements EngineRequest<Operation>
 */
final readonly class TaskStart implements EngineRequest
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        public string $key,
        public string $script,
        public array $env,
        public ?string $user,
    ) {}

    public function command(): string
    {
        return 'task start';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key, 'script' => $this->script, 'env' => (object) $this->env, 'user' => $this->user];
    }

    public function reply(): string
    {
        return Operation::class;
    }
}
