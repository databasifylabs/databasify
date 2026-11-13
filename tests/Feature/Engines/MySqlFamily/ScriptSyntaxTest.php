<?php

declare(strict_types=1);

use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Scripts\ScriptView;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

it('renders every MySQL family script to valid bash', function (string $engine) {
    $node = idleDatabase($engine, 1)->nodes()->sole();
    $scripts = Finder::create()->files()->in([resource_path('views/engines/mysql-family'), resource_path("views/engines/{$engine}")])->name('*.blade.php')->notName('_*')->notPath('#(^|/)files/#');
    $invalid = [];

    foreach ($scripts as $script) {
        $name = (str_contains($script->getPath(), 'mysql-family') ? 'mysql-family/' : "{$engine}/").str_replace('.blade.php', '', $script->getRelativePathname());
        $check = Process::input(resolve(ScriptView::class)->render($node, $name, ['engine' => MySqlFamilyEngine::of($engine)]))->run(['bash', '-n']);

        if (! $check->successful()) {
            $invalid[] = "{$name}: {$check->errorOutput()}";
        }
    }

    expect(iterator_count($scripts))->toBeGreaterThan(0)
        ->and($invalid)->toBe([]);
})->with(['mysql', 'mariadb']);

it('keeps SQL system variables inside verbatim blocks so Blade does not eat an @', function () {
    $scripts = Finder::create()->files()->in(resource_path('views/engines'))->name('*.blade.php')->notPath('#(^|/)files/#');
    $eaten = [];

    foreach ($scripts as $script) {
        $outside = (string) preg_replace('/@verbatim.*?@endverbatim/s', '', $script->getContents());

        if (str_contains($outside, '@@')) {
            $eaten[] = $script->getRelativePathname();
        }
    }

    expect($eaten)->toBe([]);
});
