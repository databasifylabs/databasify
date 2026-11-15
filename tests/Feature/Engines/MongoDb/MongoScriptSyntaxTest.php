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

it('never returns a call straight out of a try block, whose rejection the async rewriter would let escape the catch', function () {
    $offending = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines/mongodb'))->name('*.blade.php') as $script) {
        $lines = explode("\n", $script->getContents());

        foreach ($lines as $index => $line) {
            if (preg_match('/\btry \{\s*$/', $line) !== 1) {
                continue;
            }

            for ($depth = 1, $next = $index + 1; $depth > 0 && $next < count($lines); $next++) {
                if ($depth === 1 && preg_match('/^\s*\}/', $lines[$next]) === 1) {
                    break;
                }

                if ($depth === 1 && preg_match('/^\s*return [A-Za-z_$][\w$.]*\((?:(?!\)\.).)*\);\s*$/', $lines[$next]) === 1) {
                    $offending[] = $script->getRelativePathname().':'.($next + 1);
                }

                $depth += substr_count($lines[$next], '{') - substr_count($lines[$next], '}');
            }
        }
    }

    expect($offending)->toBe([]);
});

it('forces a replica set reconfig only in force-quorum, which runs when a majority is confirmed down', function () {
    $forcing = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines/mongodb'))->name('*.blade.php') as $script) {
        $contents = $script->getContents();
        preg_match_all('/^(?!function reconfig).*\breconfig\((.*)\)/m', $contents, $calls);
        $forcedCall = collect($calls[1])->contains(fn (string $arguments): bool => preg_match('/,\s*false$/', $arguments) !== 1);

        if ($forcedCall || preg_match('/force:\s*+(?!false\b|force\b)/', $contents) === 1) {
            $forcing[] = $script->getRelativePathname();
        }
    }

    expect($forcing)->toBe(['cluster/force-quorum.blade.php']);
});
