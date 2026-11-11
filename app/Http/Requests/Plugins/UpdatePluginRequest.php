<?php

declare(strict_types=1);

namespace App\Http\Requests\Plugins;

use App\DTOs\Plugins\PluginSource;
use App\Models\Plugin;
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
            'accepts_community_engines' => ['boolean'],
        ];
    }

    public function toPluginSource(Plugin $plugin): PluginSource
    {
        $archive = $this->file('archive');
        $accepts = $this->boolean('accepts_community_engines');

        return $archive instanceof UploadedFile
            ? PluginSource::fromUpload($archive, $accepts)
            : new PluginSource($plugin->source, acceptsCommunityEngines: $accepts);
    }
}
