<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Api\SystemInfo;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SystemResource;
use App\Queries\Plugins\PluginQuery;
use App\Queries\Updates\LatestReleaseQuery;

class SystemController extends Controller
{
    public function __invoke(LatestReleaseQuery $latestRelease): SystemResource
    {
        return new SystemResource(new SystemInfo(
            config()->string('app.version'),
            $latestRelease->get()?->release->version,
            $latestRelease->hasNewerRelease(),
            PluginQuery::all()->get(),
        ));
    }
}
