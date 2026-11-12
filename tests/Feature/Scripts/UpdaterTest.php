<?php

declare(strict_types=1);

function hostCommandsRecordedIn(string $calls): string
{
    return "systemctl() { echo \"systemctl \$*\" >> $calls; }; chown() { echo \"chown \$*\" >> $calls; }";
}

it('installs the units, the request folder and the marker', function () {
    $dir = temporaryDirectory();
    $units = temporaryDirectory();
    $calls = temporaryFile();

    $result = installerScript(hostCommandsRecordedIn($calls).'; LOG=/dev/null; install_updater', ['DATABASIFY_DIR' => $dir, 'SYSTEMD_DIR' => $units]);

    expect($result->successful())->toBeTrue()
        ->and(file_get_contents("$units/databasify-update.path"))->toBe(file_get_contents(base_path('scripts/databasify-update.path')))
        ->and(file_get_contents("$units/databasify-update.service"))->toBe(file_get_contents(base_path('scripts/databasify-update.service')))
        ->and(is_dir("$dir/updater/request"))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms("$dir/updater/request")), -4))->toBe('0700')
        ->and(file_exists("$dir/updater/installed"))->toBeTrue()
        ->and(file_get_contents($calls))->toContain("chown 33:33 $dir/updater/request", 'systemctl daemon-reload', 'systemctl enable --now databasify-update.path');
});

it('removes the units', function () {
    $units = temporaryDirectory();
    touch("$units/databasify-update.path");
    touch("$units/databasify-update.service");
    $calls = temporaryFile();

    installerScript(hostCommandsRecordedIn($calls).'; remove_updater', ['SYSTEMD_DIR' => $units]);

    expect(file_exists("$units/databasify-update.path"))->toBeFalse()
        ->and(file_exists("$units/databasify-update.service"))->toBeFalse()
        ->and(file_get_contents($calls))->toContain('systemctl disable --now databasify-update.path');
});

it('watches the request folder and starts the update service', function () {
    $path = (string) file_get_contents(base_path('scripts/databasify-update.path'));

    expect($path)->toContain('DirectoryNotEmpty=/opt/databasify/updater/request', 'Unit=databasify-update.service', 'WantedBy=multi-user.target');
});

it('runs only the fresh update script, fails when the download fails and never leaves a request behind', function () {
    $service = (string) file_get_contents(base_path('scripts/databasify-update.service'));

    expect($service)->toContain('Type=oneshot', "ExecStart=/bin/bash -c 'set -o pipefail; curl -fsSL https://get.databasify.io/update.sh | bash'", "ExecStopPost=/bin/sh -c 'rm -rf /opt/databasify/updater/request/*'")
        ->and($service)->not->toContain('ExecStartPre')
        ->and($service)->not->toContain('request/update');
});

it('clears the request once the update has written its status', function () {
    $dir = temporaryDirectory();
    mkdir("$dir/updater/request", 0777, true);
    touch("$dir/updater/request/update");

    installerScript('. "$SOURCE/update.sh" --functions-only; clear_update_request', ['DATABASIFY_DIR' => $dir]);

    expect(glob("$dir/updater/request/*"))->toBe([]);
});
