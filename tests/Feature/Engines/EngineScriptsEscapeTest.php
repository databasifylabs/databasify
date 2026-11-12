<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('escapes every value in engine scripts through the quoting helpers', function () {
    $scripts = Finder::create()->files()->in(resource_path('views/engines'))->name('*.blade.php');
    $unsafe = [];

    foreach ($scripts as $script) {
        $content = $script->getContents();

        if (str_contains($content, '{{') || preg_match('/\{!!(?!\s*\$(sql|sh)->)/', $content) === 1) {
            $unsafe[] = $script->getRelativePathname();
        }
    }

    expect($scripts->hasResults())->toBeTrue()
        ->and($unsafe)->toBe([]);
});

it('controls units with the in-container ctl, never the host-only service command', function () {
    $offending = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines'))->name('*.blade.php') as $script) {
        if (str_contains($script->getContents(), 'databasify service ')) {
            $offending[] = $script->getRelativePathname();
        }
    }

    expect($offending)->toBe([]);
});
