<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Plugins\PluginAutoload;
use App\Models\Plugin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plugin>
 */
class PluginFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => 'hello',
            'name' => 'acme/hello',
            'author' => 'Acme',
            'version' => '1.0.0',
            'source' => 'hello.zip (uploaded)',
            'class' => 'Acme\\Hello\\HelloPlugin',
            'autoload' => new PluginAutoload(['Acme\\Hello\\' => 'src/']),
            'engines' => [],
            'enabled' => true,
        ];
    }

    /**
     * @param  list<string>  $engines
     */
    public function withEngines(array $engines): static
    {
        return $this->state(['engines' => $engines]);
    }
}
