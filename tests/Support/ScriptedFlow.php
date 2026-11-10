<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Engines\Flow;
use App\Engines\Flows\Step;
use App\Models\DatabaseFlow;
use Closure;

final class ScriptedFlow implements Flow
{
    public int $cleanups = 0;

    /**
     * @param  list<Step>  $steps
     */
    public function __construct(private readonly array $steps) {}

    /**
     * @param  list<Step>  $steps
     */
    public static function bind(array $steps): self
    {
        $flow = new self($steps);
        app()->instance(self::class, $flow);

        return $flow;
    }

    public function name(): string
    {
        return 'Scripted flow';
    }

    public function steps(DatabaseFlow $flow): array
    {
        return $this->steps;
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        $this->cleanups++;
    }

    /**
     * @param  Closure(DatabaseFlow): mixed  $run
     */
    public static function quick(Closure $run, string $label = 'quick step'): Step
    {
        return Step::prepare($label, function (DatabaseFlow $flow) use ($run): null {
            $run($flow);

            return null;
        });
    }
}
