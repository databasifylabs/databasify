<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\DTOs\Updates\UpdateStatus;

final readonly class HostUpdater
{
    public function isInstalled(): bool
    {
        return is_file($this->pathTo('installed'));
    }

    public function pinnedVersion(): ?string
    {
        $pinned = config('databasify.updates.pinned');

        return is_string($pinned) && $pinned !== '' && $pinned !== 'latest' ? $pinned : null;
    }

    public function isRequested(): bool
    {
        return is_file($this->pathTo('request/update'));
    }

    public function status(): ?UpdateStatus
    {
        $json = $this->contentsOf('status.json');

        return $json === '' ? null : UpdateStatus::fromJson($json);
    }

    public function log(): string
    {
        return $this->contentsOf('update.log');
    }

    public function requestUpdate(): void
    {
        file_put_contents($this->pathTo('request/update'), now()->toIso8601ZuluString());
    }

    private function contentsOf(string $file): string
    {
        $path = $this->pathTo($file);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function pathTo(string $file): string
    {
        return config()->string('databasify.updates.path').'/'.$file;
    }
}
