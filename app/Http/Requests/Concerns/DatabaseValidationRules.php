<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\DatabaseNode;
use App\Rules\AvailableServer;
use App\Rules\ClusterName;
use Illuminate\Contracts\Validation\ValidationRule;

trait DatabaseValidationRules
{
    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function databaseNameRules(): array
    {
        return ['required', 'string', new ClusterName];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nodeNameRules(?DatabaseNode $except = null): array
    {
        return ['string', new ClusterName(exceptNode: $except)];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function serverIdRules(): array
    {
        return ['uuid', 'distinct', new AvailableServer];
    }
}
