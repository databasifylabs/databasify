<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Repository\DismissStarPrompt;
use Illuminate\Http\RedirectResponse;

class RepositoryStarPromptController extends Controller
{
    public function destroy(DismissStarPrompt $dismiss): RedirectResponse
    {
        $dismiss->handle();

        return back();
    }
}
