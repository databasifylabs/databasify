<?php

declare(strict_types=1);

namespace App\Http\Requests\Plugins;

use App\DTOs\Plugins\PluginSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class InstallPluginRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required_without:archive', 'nullable', 'string', 'max:255'],
            'archive' => ['required_without:source', 'nullable', 'file', 'mimes:zip', 'max:51200'],
            'accepts_community_engines' => ['boolean'],
        ];
    }

    public function toPluginSource(): PluginSource
    {
        $archive = $this->file('archive');
        $accepts = $this->boolean('accepts_community_engines');

        return $archive instanceof UploadedFile
            ? PluginSource::fromUpload($archive, $accepts)
            : new PluginSource($this->string('source')->trim()->value(), acceptsCommunityEngines: $accepts);
    }
}
