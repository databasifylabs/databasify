<?php

declare(strict_types=1);

namespace App\Actions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;

final readonly class DeleteChildren
{
    /**
     * @param  HasOneOrMany<covariant Model, covariant Model, covariant mixed>  ...$relations
     */
    public function handle(HasOneOrMany ...$relations): void
    {
        collect($relations)->each($this->deleteEachOf(...));
    }

    /**
     * @param  HasOneOrMany<covariant Model, covariant Model, covariant mixed>  $relation
     */
    private function deleteEachOf(HasOneOrMany $relation): void
    {
        $relation->reorder()->lazyById()->each(fn (Model $child): ?bool => $child->delete());
    }
}
