<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('reaches engines only through drivers and the runtime', function () {
    $callers = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), '| databasify ') || str_contains($file->getContents(), 'EngineClient'))
        ->map(fn (SplFileInfo $file): string => str_replace(app_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($callers)->toBe(['Engines/Runtime/Runtime.php']);
});
