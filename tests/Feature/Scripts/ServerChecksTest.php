<?php

declare(strict_types=1);

it('accepts the supported systems', function (string $osRelease, string $name) {
    $result = installerScript('supported_os_name '.temporaryFile($osRelease));

    expect($result->successful())->toBeTrue()->and(trim($result->output()))->toBe($name);
})->with([
    ["NAME=\"Ubuntu\"\nID=ubuntu\nVERSION_ID=\"24.04\"", 'Ubuntu 24.04'],
    ["NAME=\"Ubuntu\"\nID=ubuntu\nVERSION_ID=\"26.04\"", 'Ubuntu 26.04'],
    ["NAME=\"Debian GNU/Linux\"\nID=debian\nVERSION_ID=\"12\"", 'Debian 12'],
    ["NAME=\"Debian GNU/Linux\"\nID=debian\nVERSION_ID=\"13\"", 'Debian 13'],
    ["NAME=\"Rocky Linux\"\nID=\"rocky\"\nVERSION_ID=\"9.4\"", 'Rocky Linux 9.4'],
    ["NAME=\"AlmaLinux\"\nID=\"almalinux\"\nVERSION_ID=\"9.4\"", 'AlmaLinux 9.4'],
]);

it('refuses other systems', function (string $osRelease, string $name) {
    $result = installerScript('supported_os_name '.temporaryFile($osRelease));

    expect($result->failed())->toBeTrue()->and(trim($result->output()))->toBe($name);
})->with([
    ["NAME=\"Fedora Linux\"\nID=fedora\nVERSION_ID=41", 'Fedora Linux 41'],
    ["NAME=\"Ubuntu\"\nID=ubuntu\nVERSION_ID=\"22.04\"", 'Ubuntu 22.04'],
    ["NAME=\"Debian GNU/Linux\"\nID=debian\nVERSION_ID=\"11\"", 'Debian 11'],
    ["NAME=\"Rocky Linux\"\nID=\"rocky\"\nVERSION_ID=\"8.10\"", 'Rocky Linux 8.10'],
    ["NAME=\"Debian GNU/Linux\"\nID=debian", 'Debian'],
]);
