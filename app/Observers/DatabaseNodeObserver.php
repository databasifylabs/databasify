<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\DatabaseNode;

final readonly class DatabaseNodeObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(DatabaseNode $node): void
    {
        $this->children->handle($node->metricSamples(), $node->alerts());
        $node->migrations()->update(['database_node_id' => null]);
    }
}
