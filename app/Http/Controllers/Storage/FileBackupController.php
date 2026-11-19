<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\Storage\DeleteFileBackupDestination;
use App\Actions\Storage\QueueFileBackup;
use App\Actions\Storage\SaveFileBackupDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\SaveFileBackupRequest;
use App\Models\FileBackupDestination;
use Illuminate\Http\RedirectResponse;

class FileBackupController extends Controller
{
    public function index(): RedirectResponse
    {
        return to_route('storage-providers.index');
    }

    public function store(SaveFileBackupRequest $request, SaveFileBackupDestination $save): RedirectResponse
    {
        $save->handle($request->toDestination());

        return to_route('storage-providers.index');
    }

    public function update(SaveFileBackupRequest $request, FileBackupDestination $destination, SaveFileBackupDestination $save): RedirectResponse
    {
        $save->handle($request->toDestination($destination));

        return back();
    }

    public function backup(FileBackupDestination $destination, QueueFileBackup $queue): RedirectResponse
    {
        $queue->handle($destination);

        return back();
    }

    public function destroy(FileBackupDestination $destination, DeleteFileBackupDestination $delete): RedirectResponse
    {
        $delete->handle($destination);

        return back();
    }
}
