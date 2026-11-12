<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->updater = installedUpdater();
    config()->set('app.version', '0.1.0');
    config()->set('databasify.updates.pinned', null);
    $this->actingAs(User::factory()->create());
});

it('shows the pending release with its notes', function () {
    storeRelease('0.2.0', "## The app\n\n- Update from the app");

    $this->get('/updates')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('updates/index')
        ->where('currentVersion', '0.1.0')
        ->where('release.version', '0.2.0')
        ->where('release.url', 'https://github.com/databasifylabs/databasify/releases/tag/0.2.0')
        ->where('release.notesHtml', fn (string $html) => str_contains($html, '<li>Update from the app</li>'))
        ->where('updateAvailable', true)
        ->where('pinnedVersion', null)
        ->where('status', null)
        ->where('updateRequested', false)
        ->has('checkedAt')
        ->where('nextCheckAt', now()->addDay()->startOfDay()->toIso8601ZuluString()));
});

it('shows a failed update with the end of its log', function () {
    storeRelease('0.2.0', '');
    file_put_contents($this->updater.'/status.json', updateStatusJson('failed', true, 'Waiting until healthy'));
    file_put_contents($this->updater.'/update.log', implode("\n", array_map(fn (int $line): string => "line {$line}", range(1, 30)))."\n");

    $this->get('/updates')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('status.state', 'failed')
        ->where('status.rolledBack', true)
        ->where('status.steps.2', ['title' => 'Waiting until healthy', 'state' => 'failed'])
        ->where('logTail', array_map(fn (int $line): string => "line {$line}", range(11, 30))));
});

it('shows a pinned install', function () {
    config()->set('databasify.updates.pinned', '0.1.0');

    $this->get('/updates')->assertInertia(fn (AssertableInertia $page) => $page->where('pinnedVersion', '0.1.0'));
});

it('checks now and returns to the page', function () {
    Http::fake(['get.databasify.io/*' => Http::response(['version' => '0.3.0', 'published_at' => '2026-11-20T08:00:00Z', 'url' => 'u', 'notes' => ''])]);

    $this->from('/updates')->post('/updates/check')->assertRedirect('/updates');

    $this->get('/updates')->assertInertia(fn (AssertableInertia $page) => $page->where('release.version', '0.3.0'));
});

it('reports a check that could not reach the release feed', function () {
    Http::fake(['get.databasify.io/*' => Http::response('', 404)]);

    $this->from('/updates')->post('/updates/check')->assertRedirect('/updates')->assertSessionHasErrors('check');
});

it('starts an update and refuses a second one while it runs', function () {
    storeRelease('0.2.0', '');

    $this->from('/updates')->post('/updates')->assertRedirect('/updates')->assertSessionHasNoErrors();
    expect(file_exists($this->updater.'/request/update'))->toBeTrue();

    file_put_contents($this->updater.'/status.json', runningStatusJson());
    $this->from('/updates')->post('/updates')->assertSessionHasErrors('update');
});

it('sends the latest log as a download', function () {
    file_put_contents($this->updater.'/update.log', "line one\n");

    $this->get('/updates/log')->assertDownload('databasify-update.log');
});

it('hides updates on an install without the updater', function () {
    unlink($this->updater.'/installed');

    $this->get('/updates')->assertNotFound();
    $this->post('/updates')->assertNotFound();
    $this->post('/updates/check')->assertNotFound();
    $this->get('/updates/log')->assertNotFound();
    expect(file_exists($this->updater.'/request/update'))->toBeFalse();
});

it('keeps guests out', function () {
    auth()->logout();

    $this->get('/updates')->assertRedirect('/login');
    $this->post('/updates')->assertRedirect('/login');
    $this->get('/updates/log')->assertRedirect('/login');
});

it('shows an update that was requested but has not started yet', function () {
    storeRelease('0.2.0', '');
    $this->post('/updates');

    $this->get('/updates')->assertInertia(fn (AssertableInertia $page) => $page->where('updateRequested', true));
});
