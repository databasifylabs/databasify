<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

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
        return $this->views->make($this->viewOf($script), [...$data, 'line' => $node->database->version_line, 'sh' => new Shell])->render();
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
}
