<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\UpdatePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Http\Resources\PasskeyResource;
use App\Models\User;
use App\Queries\Settings\PasskeyQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request, #[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/security', [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => $this->passkeysOf($user),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ...$this->twoFactorStateOf($request, $user),
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function passkeysOf(User $user): array
    {
        if (! Features::canManagePasskeys()) {
            return [];
        }

        return PasskeyResource::collection(PasskeyQuery::of($user)->get())->resolve();
    }

    /**
     * @return array{twoFactorEnabled?: bool, requiresConfirmation?: bool}
     */
    private function twoFactorStateOf(TwoFactorAuthenticationRequest $request, User $user): array
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            return [];
        }

        $request->ensureStateIsValid();

        return [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        ];
    }

    public function update(PasswordUpdateRequest $request, #[CurrentUser] User $user, UpdatePassword $update): RedirectResponse
    {
        $update->handle($user, $request->string('password')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
