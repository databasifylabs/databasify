<?php

declare(strict_types=1);

namespace App\Http\Controllers\Providers;

use App\Actions\Networks\DeleteNetwork;
use App\Http\Controllers\Controller;
use App\Models\Network;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NetworkController extends Controller
{
    public function destroy(Request $request, Network $network, DeleteNetwork $deleteNetwork): RedirectResponse
    {
        $deleteNetwork->handle($network, $request->boolean('destroy_at_provider'));

        return back();
    }
}
