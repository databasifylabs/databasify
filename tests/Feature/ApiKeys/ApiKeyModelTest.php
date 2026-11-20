<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('a key reaches all projects until it is limited', function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Databasify', '--no-interaction' => true]);
    $key = User::factory()->create()->createToken('ci')->token;

    expect($key)->toBeInstanceOf(ApiKey::class)
        ->and($key->allowedProjectIds())->toBeNull();
});

test('a limited key loses a project when the project is deleted', function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Databasify', '--no-interaction' => true]);
    $project = Project::factory()->create();
    Project::factory()->create();
    $key = User::factory()->create()->createToken('ci')->token;
    $key->forceFill(['all_projects' => false])->save();
    $key->projects()->attach($project);

    expect($key->allowedProjectIds())->toBe([$project->id]);

    app(DeleteProject::class)->handle($project);

    expect($key->refresh()->allowedProjectIds())->toBe([]);
});
