<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\Engines\Data\Pool;

interface Pools
{
    /**
     * @return list<Pool>
     */
    public function list(): array;

    public function create(Pool $pool): void;

    public function update(Pool $pool): void;

    public function delete(string $name): void;
}
