<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Engines\Data\Pool;
use App\Models\DatabaseNode;

final readonly class SyncNodePools
{
    /**
     * @param  array<string, Pool>  $wanted
     */
    public function handle(DatabaseNode $node, array $wanted): void
    {
        $current = collect($node->driver()->pools()->list())->keyBy('name');
        $current->keys()->diff(array_keys($wanted))->each(fn (string $name) => $node->driver()->pools()->delete($name));

        foreach ($wanted as $name => $pool) {
            $existing = $current->get($name);

            if ($existing == $pool) {
                continue;
            }

            $existing === null ? $node->driver()->pools()->create($pool) : $node->driver()->pools()->update($pool);
        }
    }
}
