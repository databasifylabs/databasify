<?php

declare(strict_types=1);

use App\Engines\Scripts\ScriptView;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

it('renders every MongoDB script to valid bash and valid JavaScript', function () {
    $node = idleDatabase('mongodb', 1)->nodes()->sole();
    $scripts = Finder::create()->files()->in(resource_path('views/engines/mongodb'))->name('*.blade.php')->notName('_*');
    $invalid = [];

    foreach ($scripts as $script) {
        $name = 'engines.mongodb.'.str_replace(['.blade.php', '/'], ['', '.'], $script->getRelativePathname());
        $rendered = resolve(ScriptView::class)->render($node, $name);
        $bash = Process::input($rendered)->run(['bash', '-n']);

        if (! $bash->successful()) {
            $invalid[] = "{$name}: {$bash->errorOutput()}";
        }

        preg_match_all("/<<'JS'\n(.*?)\nJS\n/s", $rendered, $blocks);

        foreach ($blocks[1] as $index => $block) {
            $file = tempnam(sys_get_temp_dir(), 'js').'.js';
            file_put_contents($file, $block);
            $node_check = Process::run(['node', '--check', $file]);
            unlink($file);

            if (! $node_check->successful()) {
                $invalid[] = "{$name} block {$index}: {$node_check->errorOutput()}";
            }
        }
    }

    expect(iterator_count($scripts))->toBeGreaterThan(0)
        ->and($invalid)->toBe([]);
});

it('never closes a mongosh connection in a finally block, which the async rewriter runs before the awaited command', function () {
    $offending = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines/mongodb'))->name('*.blade.php') as $script) {
        if (preg_match('/\bfinally\b/', $script->getContents()) === 1) {
            $offending[] = $script->getRelativePathname();
        }
    }

    expect($offending)->toBe([]);
});
