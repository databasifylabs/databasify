<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

use App\Models\OAuthClient;
use Illuminate\Support\Str;

enum AiAgent: string
{
    case ClaudeCode = 'claude-code';
    case Claude = 'claude';
    case ChatGpt = 'chatgpt';
    case Cursor = 'cursor';
    case VsCode = 'vs-code';
    case GeminiCli = 'gemini-cli';
    case Codex = 'codex';
    case Zed = 'zed';

    public static function forClient(OAuthClient $client): ?self
    {
        $name = Str::lower($client->name);
        $redirects = collect($client->redirect_uris);

        return collect(self::cases())->first(fn (self $agent): bool => Str::contains($name, $agent->clientNames())
            || ($redirects->isNotEmpty() && $redirects->every(fn (string $uri): bool => Str::startsWith($uri, $agent->redirectPrefixes()))));
    }

    /**
     * @return list<array{value: string, label: string, icon: string|null, api_key: bool, public_url: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $agent): array => [
            'value' => $agent->value,
            'label' => $agent->label(),
            'icon' => $agent->icon(),
            'api_key' => $agent->supportsApiKey(),
            'public_url' => $agent->needsPublicUrl(),
        ], self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::Claude => 'Claude',
            self::ChatGpt => 'ChatGPT',
            self::Cursor => 'Cursor',
            self::VsCode => 'VS Code',
            self::GeminiCli => 'Gemini CLI',
            self::Codex => 'Codex',
            self::Zed => 'Zed',
        };
    }

    public function icon(): ?string
    {
        $path = "assets/icons/ai-agents/{$this->value}.svg";

        return file_exists(public_path($path)) ? asset($path) : null;
    }

    public function supportsApiKey(): bool
    {
        return $this !== self::ChatGpt;
    }

    public function needsPublicUrl(): bool
    {
        return in_array($this, [self::Claude, self::ChatGpt], true);
    }

    /**
     * @return list<string>
     */
    private function clientNames(): array
    {
        return match ($this) {
            self::ClaudeCode => ['claude code'],
            self::Claude => ['claude'],
            self::ChatGpt => ['chatgpt'],
            self::Cursor => ['cursor'],
            self::VsCode => ['visual studio code', 'vs code', 'vscode'],
            self::GeminiCli => ['gemini'],
            self::Codex => ['codex'],
            self::Zed => ['zed'],
        };
    }

    /**
     * @return list<string>
     */
    private function redirectPrefixes(): array
    {
        return match ($this) {
            self::Claude => ['https://claude.ai/', 'https://claude.com/'],
            self::ChatGpt => ['https://chatgpt.com/', 'https://chat.openai.com/'],
            self::Cursor => ['https://www.cursor.com/', 'https://cursor.com/'],
            self::VsCode => ['https://vscode.dev/'],
            default => [],
        };
    }
}
