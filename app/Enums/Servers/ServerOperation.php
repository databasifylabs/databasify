<?php

declare(strict_types=1);

namespace App\Enums\Servers;

use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;

enum ServerOperation: string
{
    case UpdatePackages = 'update-packages';
    case Reboot = 'reboot';

    public function title(): string
    {
        return match ($this) {
            self::UpdatePackages => 'Update packages',
            self::Reboot => 'Reboot',
        };
    }

    public function jobFor(Server $server): ShouldQueue
    {
        return match ($this) {
            self::UpdatePackages => new UpdateServerPackagesJob($server),
            self::Reboot => new RebootServerJob($server),
        };
    }
}
