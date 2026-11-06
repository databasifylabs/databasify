<?php

declare(strict_types=1);

namespace App\Plugins;

use Illuminate\Support\ServiceProvider;
use LogicException;

abstract class AbstractPlugin extends ServiceProvider
{
    private ?PluginContext $context = null;

    final public function useContext(PluginContext $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function install(): void {}

    public function uninstall(): void {}

    public function enable(): void {}

    public function disable(): void {}

    final protected function databasify(): PluginContext
    {
        return $this->context ?? throw new LogicException('The plugin loader sets the context before register() runs.');
    }
}
