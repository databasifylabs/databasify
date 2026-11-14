<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Network;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeleteNetwork
{
    public function handle(Network $network, bool $destroyAtProvider = false): void
    {
        $network->ensureDeletable();
        $provider = $network->serverProvider;

        if ($destroyAtProvider && $provider !== null && $network->external_id !== null) {
            try {
                $provider->provider()->deleteNetwork($network->external_id);
            } catch (ProviderRequestFailed $exception) {
                throw ValidationException::withMessages(['network' => "Could not delete {$network->name} at {$provider->label()}: {$exception->getMessage()}"]);
            }
        }

        DB::transaction(fn () => $network->delete());
    }
}
