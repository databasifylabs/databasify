<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\SqlQuoting;
use App\Models\DatabaseNode;
use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;

final readonly class ScriptView
{
    public function __construct(private Factory $views) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(DatabaseNode $node, string $script, array $data = []): string
    {
        $database = $node->database;

        return $this->views->make($this->viewOf($script), [...$data, 'line' => $database->version_line, 'sql' => $this->quotingFor($database->engine), 'sh' => new Shell])->render();
    }

    /**
     * @return view-string
     */
    private function viewOf(string $script): string
    {
        if (! $this->views->exists($script)) {
            throw new InvalidArgumentException("There is no engine script {$script}.");
        }

        return $script;
    }

    private function quotingFor(string $engine): SqlQuoting
    {
        return match ($engine) {
            'postgres' => new PostgresSql,
            'mysql', 'mariadb' => new MySqlSql,
            default => new EnvOnlyQuoting,
        };
    }
}
