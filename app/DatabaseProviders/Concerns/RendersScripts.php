<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Concerns;

use App\Exceptions\Databases\MissingEngineScript;

trait RendersScripts
{
    public function scriptView(string $name): string
    {
        $view = $this->viewNamed($name);

        if (! view()->exists($view)) {
            throw MissingEngineScript::named($this->database->engine, $name, $view);
        }

        return $view;
    }

    public function versionLine(): string
    {
        return $this->database->version_line ?: config()->string($this->key('default_line'), '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function render(string $name, array $data = []): string
    {
        return view($this->scriptView($name), [...$this->scriptVariables(), ...$data])->render();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function shell(string $process, array $data = []): array
    {
        return ['sh', '-c', trim($this->render("processes.{$process}", $data))];
    }

    private function viewNamed(string $name): string
    {
        $line = $this->versionLine();
        $override = $this->overridesFor($line)[$name] ?? null;

        if (is_string($override)) {
            return $override;
        }

        return config()->string($this->key('scripts'), '').".lines.{$this->folderOf($line)}.{$name}";
    }

    private function folderOf(string $line): string
    {
        return str_replace('.', '_', $line);
    }

    /**
     * @return array<mixed>
     */
    private function overridesFor(string $line): array
    {
        $overrides = config()->array($this->key('script_overrides'), [])[$line] ?? [];

        return is_array($overrides) ? $overrides : [];
    }
}
