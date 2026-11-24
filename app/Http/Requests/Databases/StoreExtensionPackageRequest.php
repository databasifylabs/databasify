<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\PackageRequest;
use App\Enums\Databases\PackageFormat;
use App\Enums\Databases\PackageSource;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreExtensionPackageRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::enum(PackageSource::class)],
            'package' => ['required_unless:source,upload', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9+.-]{1,99}$/'],
            'repository' => ['required_if:source,repository', 'nullable', 'string', 'max:500', 'regex:'.$this->packageFormat()->repositoryPattern()],
            'repository_key' => ['required_if:source,repository', 'nullable', 'string', 'max:65536'],
            'files' => ['required_if:source,upload', 'array', 'max:10', function (string $attribute, mixed $files, Closure $fail): void {
                $names = array_map(fn (mixed $file): string => $file instanceof UploadedFile ? $this->storedNameOf($file) : '', Arr::wrap($files));

                if (count($names) !== count(array_unique($names))) {
                    $fail('Two files have the same name. Rename one and upload again.');
                }
            }],
            'files.*' => ['file', 'extensions:'.$this->packageFormat()->value, 'max:204800'],
        ];
    }

    public function toPackageRequest(): PackageRequest
    {
        $source = $this->enum('source', PackageSource::class) ?? PackageSource::Official;
        $folder = 'extension-uploads/'.Str::lower(Str::ulid()->toBase32());
        $uploads = $source === PackageSource::Upload
            ? array_values(array_map(
                fn (UploadedFile $file): string => $file->storeAs($folder, $this->storedNameOf($file), 'local') ?: throw new RuntimeException("Could not keep {$file->getClientOriginalName()}."),
                Arr::wrap($this->file('files')),
            ))
            : [];

        return new PackageRequest(
            $source,
            $source === PackageSource::Upload ? null : $this->string('package')->value(),
            $source === PackageSource::Repository ? $this->packageFormat()->savedRepository($this->string('repository')->value()) : null,
            $source === PackageSource::Repository ? $this->string('repository_key')->value() : null,
            $uploads,
        );
    }

    private function storedNameOf(UploadedFile $file): string
    {
        return preg_replace('/[^A-Za-z0-9._+-]/', '_', $file->getClientOriginalName()) ?? 'package';
    }

    private function packageFormat(): PackageFormat
    {
        $database = $this->routeDatabase();

        return $database->facts()->packageFormat
            ?? throw ValidationException::withMessages(['source' => "{$database->engine()->label} databases have no extension packages."]);
    }
}
