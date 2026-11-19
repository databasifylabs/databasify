<?php

declare(strict_types=1);

use App\Engines\Scripts\Shell;
use App\Models\FileBackupDestination;

test('sql exports use each engine native dump tool', function (string $engine, string $tool) {
    $destination = new FileBackupDestination(['id' => 'backup-test', 'protocol' => 'sftp', 'host' => 'backup.example.com', 'port' => 22, 'username' => 'backup', 'path' => '/backups', 'host_key' => 'ssh-ed25519 AAAA', 'credentials' => ['password' => 'sftp-secret', 'encryption_password' => 'encryption-secret']]);
    $script = view('ssh.backups.export', ['destination' => $destination, 'engine' => $engine, 'container' => 'databasify-'.$engine, 'line' => '18', 'port' => 25060, 'filename' => 'backup.sql.gz.gpg', 'manifest' => '{}', 'sh' => new Shell])->render();

    expect($script)->toContain($tool, '--passphrase-file', 'REMOTE_HASH=', 'complete.json', 'trap cleanup_file_backup EXIT')
        ->not->toContain('--password=sftp-secret', 'rclone sync', 'rclone purge');
})->with(['postgres' => ['postgres', 'pg_dumpall'], 'mysql' => ['mysql', 'mysqldump'], 'mariadb' => ['mariadb', 'mariadb-dump']]);

test('ftps probes require explicit tls and certificate checking', function () {
    $destination = new FileBackupDestination(['id' => 'backup-test', 'protocol' => 'ftps', 'host' => 'backup.example.com', 'port' => 21, 'username' => 'backup', 'path' => '/backups', 'credentials' => ['password' => 'secret']]);
    $script = view('ssh.backups.probe', ['destination' => $destination, 'probe' => 'test123', 'sh' => new Shell])->render();

    expect($script)->toContain('RCLONE_CONFIG_TARGET_EXPLICIT_TLS=true', 'RCLONE_CONFIG_TARGET_NO_CHECK_CERTIFICATE=false', 'cmp "$WORK/probe" "$WORK/read"', 'cleanup_probe');
});

test('sftp uses the supplied host key algorithm and never disables key validation', function () {
    $destination = new FileBackupDestination(['id' => 'backup-test', 'protocol' => 'sftp', 'host' => 'backup.example.com', 'port' => 22, 'username' => 'backup', 'path' => '/backups', 'host_key' => 'ssh-ed25519 AAAA', 'credentials' => ['password' => 'secret']]);
    $script = view('ssh.backups.probe', ['destination' => $destination, 'probe' => 'test123', 'sh' => new Shell])->render();

    expect($script)->toContain("KEY_ALGORITHMS='ssh-ed25519'", '--sftp-known-hosts-file', '--sftp-host-key-algorithms')
        ->not->toContain('known_hosts_file=none', '--sftp-pin-host-key');
});
