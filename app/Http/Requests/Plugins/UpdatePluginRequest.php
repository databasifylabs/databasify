<?php

declare(strict_types=1);

namespace App\Http\Requests\Plugins;

use App\DTOs\Plugins\PluginSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdatePluginRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archive' => ['nullable', 'file', 'mimes:zip', 'max:51200'],
        ];
    }

    public function toPluginSource(): ?PluginSource
    {
        $archive = $this->file('archive');

        return $archive instanceof UploadedFile ? PluginSource::fromUpload($archive) : null;
    }
}
