<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Network\AddTrustedSource;
use App\Actions\Databases\Network\RemoveTrustedSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreTrustedSourceRequest;
use App\Models\Database;
use App\Models\TrustedSource;
use Illuminate\Http\RedirectResponse;

class TrustedSourceController extends Controller
{
    public function store(StoreTrustedSourceRequest $request, Database $database, AddTrustedSource $addSource): RedirectResponse
    {
        $addSource->handle($database, $request->toSource());

        return back();
    }

    public function destroy(Database $database, TrustedSource $trustedSource, RemoveTrustedSource $removeSource): RedirectResponse
    {
        $removeSource->handle($trustedSource);

        return back();
    }
}
