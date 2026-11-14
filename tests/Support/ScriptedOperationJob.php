<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

#[Tries(1)]
final class ScriptedOperationJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    /** @var (Closure(Database): void)|null */
    public static ?Closure $work = null;

    public static int $runs = 0;

    public function __construct(public Database $database) {}

    /**
     * @param  Closure(Database): void  $work
     */
    public static function script(Closure $work): void
    {
        self::$work = $work;
        self::$runs = 0;
    }

    public function handle(): void
    {
        self::$runs++;

        if (self::$work !== null) {
            (self::$work)($this->database);
        }
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Upgrading;
    }

    protected function operationName(): string
    {
        return 'Scripted';
    }
}
