<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Http\Requests\Providers\StoreProviderServerRequest;
use App\Models\ServerProvider;

class OrderServerRequest extends StoreProviderServerRequest
{
    use ResolvesRouteModel;

    protected function prepareForValidation(): void
    {
        $this->merge(['server_provider_id' => $this->routeModel('provider', ServerProvider::class)->id]);
    }
}
