<?php

declare(strict_types=1);

function updateDirectory(string $env, string $example): string
{
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", $env);
    file_put_contents("$dir/.env.example", $example);

    return $dir;
}

it('reports the new settings it adds', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nREVERB_SCALING=false\nMAIL_PORT=587\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 2 new settings added (REVERB_SCALING, MAIL_PORT)')
        ->and(file_get_contents("$dir/.env"))->toContain('REVERB_SCALING=false', 'MAIL_PORT=587');
});

it('reports a single new setting in the singular', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nREVERB_SCALING=false\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 1 new setting added (REVERB_SCALING)');
});

it('prints nothing and keeps .env when no setting is missing', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\n");

    $output = installerScript('. "$SOURCE/update.sh" --functions-only; LOG=/dev/null; settings_step', ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toBe('')
        ->and(file_get_contents("$dir/.env"))->toBe("APP_NAME=Databasify\n");
});

it('adds a repeated setting only once', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nMAIL_PORT=587\nMAIL_PORT=587\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 1 new setting added (MAIL_PORT)')
        ->and(substr_count(file_get_contents("$dir/.env"), 'MAIL_PORT='))->toBe(1);
});
