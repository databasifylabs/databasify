<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Plugins\AbstractPlugin;
use App\Plugins\PluginContext;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

final readonly class PluginLoader
{
    private const array REGISTRIES = ['engine', 'dns-provider', 'server-provider'];

    public function __construct(private Application $app) {}

    public function reset(): void
    {
        config(array_fill_keys(array_map(fn (string $registry): string => "{$registry}.providers", self::REGISTRIES), []));
    }

    public function boot(): void
    {
        foreach ($this->enabled() as $plugin) {
            $plugin->recordError(Plugin::incompatibilityWith($plugin->requires) ?? $this->load($plugin));
        }
    }

    public function load(Plugin $plugin): ?string
    {
        $before = config()->getMany(self::REGISTRIES);

        try {
            $this->start($this->providerOf($plugin));
        } catch (Throwable $exception) {
            config($before);
            report($exception);

            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param  'install'|'uninstall'|'enable'|'disable'  $hook
     */
    public function call(Plugin $plugin, string $hook): void
    {
        $this->app->call([$this->providerOf($plugin), $hook]);
    }

    private function providerOf(Plugin $plugin): AbstractPlugin
    {
        return $this->instantiate($plugin)->useContext(new PluginContext($plugin));
    }

    private function start(AbstractPlugin $provider): void
    {
        $provider->register();
        $provider->callBootingCallbacks();

        if (method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }

        $provider->callBootedCallbacks();
    }

    /**
     * @return Collection<int, Plugin>
     */
    private function enabled(): Collection
    {
        try {
            return Plugin::query()->enabled()->get();
        } catch (QueryException) {
            return new Collection;
        }
    }

    private function instantiate(Plugin $plugin): AbstractPlugin
    {
        $loader = new ClassLoader;

        foreach ($plugin->autoload->namespaces as $prefix => $path) {
            $loader->addPsr4($prefix, "{$plugin->directory()}/{$path}");
        }

        $loader->register();

        if (is_file("{$plugin->directory()}/vendor/autoload.php")) {
            require_once "{$plugin->directory()}/vendor/autoload.php";
        }

        $class = $plugin->class;

        if (! is_subclass_of($class, AbstractPlugin::class)) {
            throw new RuntimeException("{$class} is not a Databasify plugin class.");
        }

        return new $class($this->app);
    }
}
