<?php

declare(strict_types=1);

it('downloads the compose file and env template from the source into the install folder', function () {
    $dir = temporaryPath();

    $result = installerScript('download_release_files', ['DATABASIFY_DIR' => $dir]);

    expect($result->successful())->toBeTrue()
        ->and(file_get_contents("$dir/compose.yaml"))->toBe(file_get_contents(base_path('scripts/compose.yaml')))
        ->and(file_get_contents("$dir/.env.example"))->toBe(file_get_contents(base_path('scripts/env.example')))
        ->and(is_dir("$dir/scripts"))->toBeFalse();
});
