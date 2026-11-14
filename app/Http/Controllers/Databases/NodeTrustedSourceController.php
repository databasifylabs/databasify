<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Network\AddNodeTrustedSource;
use App\Actions\Databases\Network\RemoveNodeTrustedSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreTrustedSourceRequest;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\TrustedSource;
use Illuminate\Http\RedirectResponse;

final class NodeTrustedSourceController extends Controller
{
    public function store(StoreTrustedSourceRequest $request, Database $database, DatabaseNode $node, AddNodeTrustedSource $addSource): RedirectResponse
    {
        $addSource->handle($node, $request->toSource());

        return back();
    }

    public function destroy(Database $database, DatabaseNode $node, TrustedSource $trustedSource, RemoveNodeTrustedSource $removeSource): RedirectResponse
    {
        $removeSource->handle($node, $trustedSource);

        return back();
    }
}
