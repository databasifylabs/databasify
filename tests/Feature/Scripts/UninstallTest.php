<?php

declare(strict_types=1);

it('asks to rerun with DATABASIFY_YES when it cannot ask for confirmation', function () {
    $result = installerScript('. "$SOURCE/uninstall.sh" --functions-only; has_terminal() { return 1; }; confirm_purge', ['DATABASIFY_YES' => 'false']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Rerun with DATABASIFY_YES=true to confirm.');
});

it('skips the confirmation when DATABASIFY_YES is true', function () {
    $result = installerScript('. "$SOURCE/uninstall.sh" --functions-only; confirm_purge', ['DATABASIFY_YES' => 'true']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe('');
});

it('refuses to purge a directory that is not a clean absolute path two levels deep', function (string $dir) {
    $stubs = 'marker=$(mktemp); compose() { echo "COMPOSE $*" >> "$marker"; }; rm() { echo "RM $*" >> "$marker"; }; docker() { echo "DOCKER $*" >> "$marker"; }; LOG=/dev/null';
    $snippet = ". \"\$SOURCE/uninstall.sh\" --functions-only; $stubs; status=0; ( purge ) || status=\$?; echo \"MARKER[\$(cat \"\$marker\")]\"; command rm -f \"\$marker\"; exit \$status";

    $result = installerScript($snippet, ['DATABASIFY_DIR' => $dir, 'DATABASIFY_YES' => 'true']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Refusing to remove')->toContain('MARKER[]');
})->with(['/', '//', '/.', 'relative/path', '/opt/', '/opt/..', '/opt/.', '/opt/../etc', '//opt']);

it('runs the purge steps for a clean directory', function () {
    $dir = temporaryDirectory().'/databasify';
    mkdir($dir);
    file_put_contents($dir.'/compose.yaml', '');
    $stubs = 'marker=$(mktemp); compose() { echo "COMPOSE $*" >> "$marker"; }; LOG=/dev/null';
    $snippet = ". \"\$SOURCE/uninstall.sh\" --functions-only; $stubs; purge > /dev/null; cat \"\$marker\"; command rm -f \"\$marker\"";

    $output = installerScript($snippet, ['DATABASIFY_DIR' => $dir, 'DATABASIFY_YES' => 'true'])->output();

    expect($dir)->not->toBeDirectory()
        ->and($output)->toContain('COMPOSE down --remove-orphans')->toContain('COMPOSE down --volumes --remove-orphans');
});
