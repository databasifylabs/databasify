<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\Projects\CurrentProjectQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

class HomeController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, CurrentProjectQuery $currentProject): RedirectResponse
    {
        $project = $currentProject->for($user);

        if ($project === null) {
            return to_route('projects.index');
        }

        return to_route('dashboard', ['project' => $project]);
    }
}
