<?php

declare(strict_types=1);

use App\Actions\Databases\Upgrades\ApplyMaintenance;
use App\Contracts\Images\ImageRegistry;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImageRegistry;

test('maintenance upgrades a healthy database to a newer supported image', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.2' => '0.2.2', '18.1' => '0.2.2']));
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();

    app(ApplyMaintenance::class)->handle($database);

    expect($database->refresh())
        ->status->toBe(DatabaseStatus::Upgrading)
        ->image->release->toBe('18.2');
    Queue::assertPushed(UpgradeDatabaseJob::class);
});

test('maintenance leaves a database alone', function (Closure $arrange) {
    Queue::fake([UpgradeDatabaseJob::class]);
    app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.2' => '0.2.2', '18.1' => '0.2.2']));
    $database = Database::factory()->highlyAvailable()->create();
    $arrange($database);

    app(ApplyMaintenance::class)->handle($database);

    expect($database->refresh()->image->release)->toBe('18.1');
    Queue::assertNotPushed(UpgradeDatabaseJob::class);
})->with([
    'degraded' => [function (Database $database) {
        DatabaseNode::factory()->for($database)->create();
        DatabaseNode::factory()->for($database)->replica()->create(['role' => NodeRole::Offline]);
    }],
    'still being set up' => [function (Database $database) {
        $database->update(['status' => DatabaseStatus::Creating]);
        DatabaseNode::factory()->for($database)->create();
    }],
    'already busy' => [function (Database $database) {
        $database->update(['status' => DatabaseStatus::Rebooting]);
        DatabaseNode::factory()->for($database)->create();
    }],
]);
