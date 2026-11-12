<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\RuntimeRun as RuntimeRunReply;

/**
 * @implements EngineRequest<RuntimeRunReply>
 */
final readonly class RuntimeRun implements EngineRequest
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        public string $script,
        public array $env,
        public ?string $user,
        public int $timeoutSeconds,
    ) {}

    public function command(): string
    {
        return 'runtime run';
    }

    public function toPayload(): array
    {
        return ['script' => $this->script, 'env' => (object) $this->env, 'user' => $this->user, 'timeoutSeconds' => $this->timeoutSeconds];
    }

    public function reply(): string
    {
        return RuntimeRunReply::class;
    }
}
