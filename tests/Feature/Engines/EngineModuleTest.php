<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

test('only the runtime builds a databasify command line', function () {
    $builders = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), '| databasify '))
        ->map(fn (SplFileInfo $file): string => str_replace(str_replace('\\', '/', base_path()).'/', '', str_replace('\\', '/', $file->getPathname())))
        ->values()
        ->all();

    expect($builders)->toBe(['app/Engines/Runtime/Runtime.php']);
});
