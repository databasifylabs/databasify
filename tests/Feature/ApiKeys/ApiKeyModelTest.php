<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\ApiKey;
use App\Models\Project;

test('a key reaches all projects until it is limited', function () {
    expect(ApiKey::factory()->create()->allowedProjectIds())->toBeNull();
});

test('a limited key loses a project when the project is deleted', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    $key = ApiKey::factory()->limitedTo($project)->create();

    expect($key->allowedProjectIds())->toBe([$project->id]);

    app(DeleteProject::class)->handle($project);

    expect($key->refresh()->allowedProjectIds())->toBe([]);
});
