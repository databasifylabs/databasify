<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\DTOs\Storage\S3Bucket;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Services\Storage\S3;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CheckStorageAccess
{
    private const string PROBE_PREFIX = '.databasify-check-';

    private const string PROBE_BODY = 'databasify';

    public function handle(S3Bucket $bucket): void
    {
        $s3 = S3::make($bucket);
        $probe = $bucket->under(self::PROBE_PREFIX.Str::lower(Str::random(16)));

        try {
            $s3->put($probe, self::PROBE_BODY);
            $read = $s3->get($probe);
            $s3->delete($probe);
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['storage' => $exception->getMessage()]);
        }

        if ($read !== self::PROBE_BODY) {
            throw ValidationException::withMessages(['storage' => "Wrote a test file to {$bucket->bucket} but read back something else. Check the endpoint and addressing style."]);
        }
    }
}
