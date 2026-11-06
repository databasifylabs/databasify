<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\Storage\S3Bucket;
use Illuminate\Contracts\Validation\ValidationRule;

trait StorageProviderRules
{
    private const string CREDENTIAL_PATTERN = '/^[A-Za-z0-9\/+=._-]+$/';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function locationRules(): array
    {
        return [
            'endpoint' => ['required', 'url:https', 'max:255', 'not_regex:/[\'"`$\\\\\s]/'],
            'region' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[A-Za-z0-9.-]+$/'],
            'path_prefix' => ['nullable', 'string', 'max:200', 'regex:/^\/?[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*\/?$/'],
            'path_style' => ['boolean'],
            'access_key_id' => ['required', 'string', 'max:255', 'regex:'.self::CREDENTIAL_PATTERN],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function secretRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:255', 'regex:'.self::CREDENTIAL_PATTERN];
    }

    protected function bucketWith(string $secretAccessKey): S3Bucket
    {
        return new S3Bucket(
            endpoint: $this->string('endpoint')->trim()->rtrim('/')->value(),
            region: $this->string('region')->value(),
            bucket: $this->string('bucket')->value(),
            pathPrefix: $this->string('path_prefix')->trim()->trim('/')->value(),
            pathStyle: $this->boolean('path_style'),
            accessKeyId: $this->string('access_key_id')->value(),
            secretAccessKey: $secretAccessKey,
        );
    }
}
