<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final readonly class PluginDownloader
{
    private const string GITHUB = '#^(?:https://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int DOWNLOAD_TIMEOUT_SECONDS = 120;

    private const int RELEASE_TIMEOUT_SECONDS = 30;

    public function download(string $source, ?string $zip = null): string
    {
        if ($zip !== null) {
            return $this->unpack($zip, $source);
        }

        $zip = $this->downloadedZipOf($source);

        try {
            return $this->unpack($zip, $source);
        } finally {
            File::delete($zip);
        }
    }

    public function packageIn(string $download): string
    {
        $files = "{$download}/files";
        $folder = File::directories($files)[0] ?? null;

        return is_file("{$files}/composer.json") || ! is_string($folder) ? $files : $folder;
    }

    private function downloadedZipOf(string $source): string
    {
        $zip = storage_path('framework/plugins/'.Str::random(16).'.zip');
        File::ensureDirectoryExists(dirname($zip));

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->sink($zip)->get($this->zipUrl($source));
        } catch (ConnectionException) {
            throw $this->discarding($zip, "{$source} could not be reached.");
        }

        if ($response->failed()) {
            throw $this->discarding($zip, "Downloading {$source} failed with HTTP {$response->status()}.");
        }

        return $zip;
    }

    private function unpack(string $zip, string $source): string
    {
        $folder = storage_path('framework/plugins/'.Str::random(16));
        $archive = new ZipArchive;

        if ($archive->open($zip) !== true || ! $archive->extractTo("{$folder}/files")) {
            File::deleteDirectory($folder);

            throw $this->invalid("{$source} is not a zip file.");
        }

        $archive->close();

        return $folder;
    }

    private function zipUrl(string $source): string
    {
        if (preg_match(self::GITHUB, $source, $repository) === 1) {
            $api = "https://api.github.com/repos/{$repository[1]}/{$repository[2]}";
            $release = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::RELEASE_TIMEOUT_SECONDS)->acceptJson()->get("{$api}/releases/latest");

            return $release->successful() && is_string($release->json('zipball_url'))
                ? $release->json('zipball_url')
                : "{$api}/zipball";
        }

        if (! Str::startsWith($source, 'https://')) {
            throw $this->invalid('Enter a GitHub repository (owner/repo) or an https:// link to a zip.');
        }

        return $source;
    }

    private function discarding(string $zip, string $message): ValidationException
    {
        File::delete($zip);

        return $this->invalid($message);
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['source' => $message]);
    }
}
