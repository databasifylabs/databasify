<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Databases\DatabaseStatus;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait RunsOperations
{
    private const int STOP_TTL_SECONDS = 86400;

    abstract public function operationStatus(): ?DatabaseStatus;

    abstract public function operationLabel(): string;

    abstract protected function operationKey(): string;

    abstract protected function refusalFor(DatabaseStatus $status): ?ValidationException;

    abstract protected function endOperation(DatabaseStatus $ended): void;

    public function isBusy(): bool
    {
        return $this->operationStatus()?->isBusy() === true;
    }

    public function busyError(): ValidationException
    {
        return ValidationException::withMessages(['database' => "{$this->operationLabel()} is busy {$this->operationStatus()?->value}, try again when it finishes."]);
    }

    public function markBusy(DatabaseStatus $status): void
    {
        $this->markBusyWith($status, fn (): null => null);
    }

    /**
     * @template TResult
     *
     * @param  Closure(static): TResult  $changes
     * @return TResult
     */
    public function markBusyWith(DatabaseStatus $status, Closure $changes): mixed
    {
        return DB::transaction(function () use ($status, $changes): mixed {
            if ($this->refreshForUpdate()->isBusy()) {
                throw $this->busyError();
            }

            $refusal = $this->refusalFor($status);

            if ($refusal !== null) {
                throw $refusal;
            }

            $result = $changes($this);
            $this->update(['status' => $status]);

            return $result;
        });
    }

    public function markReady(): void
    {
        $this->finishOperationAs(DatabaseStatus::Ready);
    }

    public function markFailed(): void
    {
        $this->finishOperationAs(DatabaseStatus::Failed);
    }

    public function requestStop(): void
    {
        Cache::put($this->stopKey(), true, self::STOP_TTL_SECONDS);
    }

    /**
     * @phpstan-impure
     */
    public function isStopRequested(): bool
    {
        return Cache::has($this->stopKey()) && ! Cache::has($this->cleanupKey());
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $cleanup
     * @return TResult
     */
    public function ignoringStop(Closure $cleanup): mixed
    {
        Cache::put($this->cleanupKey(), true, self::STOP_TTL_SECONDS);

        try {
            return $cleanup();
        } finally {
            Cache::forget($this->cleanupKey());
        }
    }

    private function finishOperationAs(DatabaseStatus $status): void
    {
        $ended = $this->operationStatus();
        self::query()->whereKey($this->getKey())->update(['status' => $status]);
        $this->forceFill(['status' => $status])->syncOriginalAttributes(['status']);

        if ($ended !== null) {
            $this->endOperation($ended);
        }

        $this->clearStopRequest();
    }

    public function clearStopRequest(): void
    {
        Cache::forget($this->stopKey());
        Cache::forget($this->cleanupKey());
    }

    private function stopKey(): string
    {
        return "{$this->operationKey()}.stop";
    }

    private function cleanupKey(): string
    {
        return "{$this->operationKey()}.cleanup";
    }
}
