<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Enums\Databases\StepKind;
use App\Models\DatabaseFlow;
use Closure;

final readonly class Step
{
    /**
     * @param  Closure(DatabaseFlow): ?StartedOperation  $run
     */
    public function __construct(
        public StepKind $kind,
        public string $label,
        public Closure $run,
    ) {}

    /**
     * @param  Closure(DatabaseFlow): ?StartedOperation  $run
     */
    public static function prepare(string $label, Closure $run): self
    {
        return new self(StepKind::Prepare, $label, $run);
    }

    /**
     * @param  Closure(DatabaseFlow): ?StartedOperation  $run
     */
    public static function catchUp(string $label, Closure $run): self
    {
        return new self(StepKind::CatchUp, $label, $run);
    }

    /**
     * @param  Closure(DatabaseFlow): ?StartedOperation  $run
     */
    public static function finish(string $label, Closure $run): self
    {
        return new self(StepKind::Finish, $label, $run);
    }

    public function runFor(DatabaseFlow $flow): ?StartedOperation
    {
        return ($this->run)($flow);
    }
}
