<?php

declare(strict_types=1);

use App\Actions\Servers\QueueProvisioning;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Queue::fake([ProvisionServerJob::class]));

it('resets the server to pending and queues provisioning', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Failed]);

    resolve(QueueProvisioning::class)->handle($server);

    expect($server->fresh()->status)->toBe(ServerStatus::Pending);
    Queue::assertPushed(ProvisionServerJob::class, fn (ProvisionServerJob $job) => $job->server->is($server) && ! $job->disablePasswordLogin);
});

it('refuses a server that is already provisioning', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning]);

    expect(fn () => resolve(QueueProvisioning::class)->handle($server))->toThrow(ValidationException::class, 'is already being set up');
    Queue::assertNothingPushed();
});

it('refuses a server with a script still running', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Failed]);
    ServerLog::factory()->for($server)->create(['status' => ServerLogStatus::Running]);

    expect(fn () => resolve(QueueProvisioning::class)->handle($server))->toThrow(ValidationException::class, 'is already being set up');
    expect($server->fresh()->status)->toBe(ServerStatus::Failed);
    Queue::assertNothingPushed();
});
