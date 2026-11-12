<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\SqlQuoting;
use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;

final readonly class ScriptView
{
    public function __construct(private Factory $views) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $engine, string $script, array $data = []): string
    {
        return $this->views->make($this->viewOf($script), [...$data, 'sql' => $this->quotingFor($engine), 'sh' => new Shell])->render();
    }

    /**
     * @return view-string
     */
    private function viewOf(string $script): string
    {
        $name = 'engines.'.str_replace('/', '.', $script);

        if (! $this->views->exists($name)) {
            throw new InvalidArgumentException("There is no engine script {$script}.");
        }

        return $name;
    }

    private function quotingFor(string $engine): SqlQuoting
    {
        return match ($engine) {
            'postgres' => new PostgresSql,
            default => throw new InvalidArgumentException("No script quoting for the {$engine} engine."),
        };
    }
}
