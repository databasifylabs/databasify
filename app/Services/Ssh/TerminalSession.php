<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Models\Server;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final readonly class TerminalSession
{
    public const int IDLE_SECONDS = 900;

    public const int MAX_SECONDS = 7200;

    private const int ATTACH_SECONDS = 30;

    private const int ATTACH_POLL_MILLISECONDS = 100;

    private const int LOCK_SECONDS = 5;

    public function __construct(public string $id) {}

    public static function open(Server $server, User $user): self
    {
        $session = new self(Str::uuid7()->toString());
        Cache::put($session->key('owner'), $session->ownerKey($server, $user), self::MAX_SECONDS);
        $session->touch();

        return $session;
    }

    public function isFor(Server $server, User $user): bool
    {
        return Cache::get($this->key('owner')) === $this->ownerKey($server, $user);
    }

    public function attach(User $user): bool
    {
        $owner = Cache::get($this->key('owner'));

        if (! is_string($owner) || ! str_ends_with($owner, ":{$user->id}")) {
            return false;
        }

        Cache::put($this->key('attached'), true, self::MAX_SECONDS);

        return true;
    }

    public function waitForAttach(): bool
    {
        $deadline = now()->addSeconds(self::ATTACH_SECONDS);

        while (! Cache::has($this->key('attached'))) {
            if (! $this->isActive() || now()->greaterThan($deadline)) {
                return false;
            }

            Sleep::for(self::ATTACH_POLL_MILLISECONDS)->milliseconds();
        }

        return true;
    }

    public function push(string $input): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $this->locked(fn (): bool => Cache::put($this->key('input'), $this->pending().$input, self::MAX_SECONDS));
        $this->touch();

        return true;
    }

    public function nextInput(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        if ($this->pending() === '') {
            return '';
        }

        $input = $this->locked(fn (): mixed => Cache::pull($this->key('input')));

        return is_string($input) ? $input : '';
    }

    public function isActive(): bool
    {
        $activeAt = Cache::get($this->key('active'));

        return is_int($activeAt) && $activeAt > now()->getTimestamp() - self::IDLE_SECONDS;
    }

    public function close(): void
    {
        Cache::forget($this->key('active'));
    }

    private function touch(): void
    {
        Cache::put($this->key('active'), now()->getTimestamp(), self::MAX_SECONDS);
    }

    /**
     * @param  Closure(): mixed  $change
     */
    private function locked(Closure $change): mixed
    {
        return Cache::lock($this->key('lock'), self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $change);
    }

    private function pending(): string
    {
        $input = Cache::get($this->key('input'));

        return is_string($input) ? $input : '';
    }

    private function ownerKey(Server $server, User $user): string
    {
        return "{$server->id}:{$user->id}";
    }

    private function key(string $name): string
    {
        return "terminals.{$this->id}.{$name}";
    }
}
