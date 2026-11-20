<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Projects\StoreProjectRequest as WebStoreProjectRequest;
use App\Support\Projects\ProjectContext;

class StoreProjectRequest extends WebStoreProjectRequest
{
    public function authorize(): bool
    {
        return ! ProjectContext::current()->isLimited();
    }
}
