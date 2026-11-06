<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Database;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait ResolvesRouteModel
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    protected function routeModel(string $key, string $class): Model
    {
        $model = $this->route($key);

        if (! $model instanceof $class) {
            throw new LogicException("Route parameter [{$key}] is not a {$class}.");
        }

        return $model;
    }

    protected function routeDatabase(): Database
    {
        return $this->routeModel('database', Database::class);
    }
}
