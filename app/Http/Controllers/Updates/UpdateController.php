<?php

declare(strict_types=1);

namespace App\Http\Controllers\Updates;

use App\Actions\Updates\CheckForRelease;
use App\Actions\Updates\StartUpdate;
use App\Http\Controllers\Controller;
use App\Queries\Updates\UpdatesPageQuery;
use App\Services\Updates\HostUpdater;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UpdateController extends Controller
{
    public function index(UpdatesPageQuery $page): Response
    {
        return Inertia::render('updates/index', $page->get()->toArray());
    }

    public function check(CheckForRelease $check): RedirectResponse
    {
        $check->handle();

        return back();
    }

    public function store(StartUpdate $start): RedirectResponse
    {
        $start->handle();

        return back();
    }

    public function log(HostUpdater $updater): StreamedResponse
    {
        $log = $updater->log();

        return response()->streamDownload(function () use ($log): void {
            echo $log;
        }, 'databasify-update.log', ['Content-Type' => 'text/plain']);
    }
}
