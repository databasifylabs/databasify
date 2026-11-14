<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeSettingsJob;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class UpdateNodeConfig
{
    /**
     * @param  array<string, string>  $config
     */
    public function handle(DatabaseNode $node, array $config): void
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();
        $controlled = array_values(array_intersect(array_keys($config), $node->database->facts()->primaryControlledSettings));

        if ($controlled !== []) {
            throw ValidationException::withMessages(['config' => "{$controlled[0]} is set on the primary for every node, change it on {$node->database->name}."]);
        }

        $node->markBusyWith(DatabaseStatus::Updating, fn (DatabaseNode $locked): bool => $locked->update(['config' => $config]));
        dispatch(new ApplyNodeSettingsJob($node));
    }
}
