<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Actions\Setup\CreateOwnerAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\CreateOwnerRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/setup', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(CreateOwnerRequest $request, CreateOwnerAccount $create): RedirectResponse
    {
        Auth::login($create->handle($request->toOwner()));
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
