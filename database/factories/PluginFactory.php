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
            'key' => 'acme/hello',
            'name' => 'Hello',
            'author' => 'Acme',
            'version' => '1.0.0',
            'source' => 'hello.zip (uploaded)',
            'class' => 'Acme\\Hello\\HelloPlugin',
            'autoload' => new PluginAutoload(['Acme\\Hello\\' => 'src/']),
            'enabled' => true,
        ];
    }

    public function withEngine(string $engine): static
    {
        return $this->state(['engine' => $engine]);
    }
}
