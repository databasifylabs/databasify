<?php

declare(strict_types=1);

beforeEach(function () {
    $this->database = engineNode()->database;
    $this->scripts = fakeScripts()
        ->respond('postgres/nodes/status', ['role' => 'primary', 'version' => '18.6'])
        ->respond('postgres/accounts/list', fn (array $env): array => ['users' => ['app', $this->createdUser ?? 'none'], 'databases' => ['app', $this->createdSchema ?? 'none']])
        ->respond('postgres/accounts/create-user', function (array $env): array {
            $created = ! isset($this->createdUser);
            $this->createdUser = $env['NAME'];
            $this->createdSchema = json_decode($env['GRANTS'], true)[0]['database'];

            return ['created' => $created];
        });
    fakeSsh()->respondToEngine('task status', ['error' => ['code' => 'not_found', 'message' => 'no operation with key conformance.missing.1', 'details' => (object) []]], 1)
        ->respondToEngine('task log', ['lines' => '']);
});

it('passes every step through the PHP driver against a well-behaved engine', function () {
    $this->artisan('engines:conformance', ['database' => $this->database->name])
        ->expectsOutputToContain('PASS user create again')
        ->expectsOutputToContain('PASS operation status unknown key')
        ->assertSuccessful();
});

it('fails and names the step when an existing user is reported as created', function () {
    $this->scripts->respond('postgres/accounts/create-user', ['created' => true]);

    $this->artisan('engines:conformance', ['database' => $this->database->name])
        ->expectsOutputToContain('FAIL user create again: the existing user was reported as created')
        ->assertFailed();
});
