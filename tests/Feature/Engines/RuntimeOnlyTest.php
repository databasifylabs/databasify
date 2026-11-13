<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\DTOs\Databases\EngineImage;
use App\Support\Engines\SdkRange;
use Illuminate\Validation\ValidationException;

it('accepts sdk 1.0 images and the runtime releases of 0.2', function (?string $sdk, bool $allowed) {
    expect(SdkRange::allows($sdk))->toBe($allowed);
})->with([
    '1.0.0' => ['1.0.0', true],
    '1.4.2' => ['1.4.2', true],
    '0.2.2' => ['0.2.2', true],
    '0.2.1' => ['0.2.1', false],
    '0.3.0' => ['0.3.0', false],
    'none' => [null, false],
]);

it('makes no engine call for a database without the engine runtime', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);
    $database->update(['image' => new EngineImage($database->image->repository, $database->image->digest, $database->image->release, null)]);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database->fresh(), 'reporting'))
        ->toThrow(ValidationException::class, 'which this Databasify no longer supports')
        ->and(recordedCalls())->toBe([]);
});
