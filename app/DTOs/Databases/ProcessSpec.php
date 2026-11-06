<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\ProcessRestart;
use App\Enums\Databases\ProcessType;

final readonly class ProcessSpec
{
    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     * @param  list<string>  $after
     */
    public function __construct(
        public string $name,
        public array $argv,
        public ?string $user = null,
        public array $env = [],
        public array $after = [],
        public ?ProcessRestart $restart = null,
        public ?int $restartSeconds = null,
        public ?string $stopSignal = null,
        public ?int $stopTimeoutSeconds = null,
        public bool $enabled = false,
        public ?ProcessType $type = null,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public static function daemon(string $name, array $argv, ?string $user = null): self
    {
        return new self($name, $argv, $user, restart: ProcessRestart::Always);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function oneshot(string $name, array $argv, ?string $user = null): self
    {
        return new self($name, $argv, $user, type: ProcessType::Oneshot);
    }

    public function after(string ...$names): self
    {
        return clone ($this, ['after' => array_values($names)]);
    }

    public function restartingAfter(int $seconds): self
    {
        return clone ($this, ['restartSeconds' => $seconds]);
    }

    public function stoppedBy(string $signal, int $timeoutSeconds): self
    {
        return clone ($this, ['stopSignal' => $signal, 'stopTimeoutSeconds' => $timeoutSeconds]);
    }

    public function enabledAtBoot(): self
    {
        return clone ($this, ['enabled' => true]);
    }

    public function stopSeconds(): int
    {
        return $this->stopTimeoutSeconds ?? ProcessManifest::DEFAULT_STOP_SECONDS;
    }

    /**
     * @return array<string, string|bool|list<string>|array<string, string>>
     */
    public function toArray(): array
    {
        return array_merge(ProcessManifest::presentIn([
            'name' => $this->name,
            'type' => $this->type?->value,
            'user' => $this->user,
            'env' => $this->env,
            'after' => $this->after,
            'restart' => $this->restart?->value,
            'restartSec' => ProcessManifest::durationOf($this->restartSeconds),
            'stopSignal' => $this->stopSignal,
            'stopTimeout' => ProcessManifest::durationOf($this->stopTimeoutSeconds),
            'argv' => $this->argv,
        ]), ['enabled' => $this->enabled]);
    }
}
