<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers;

use App\DatabaseProviders\Concerns\ProvidesGroups;
use App\DatabaseProviders\Concerns\RendersScripts;
use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\EnginePorts;
use App\DTOs\Databases\TlsSettings;
use App\DTOs\Databases\VersionLine;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Databases\ClientPorts;
use Illuminate\Support\Collection;

abstract readonly class AbstractDatabaseDriver implements DatabaseDriver
{
    use ProvidesGroups;
    use RendersScripts;

    private const int MAX_STANDBYS = 2;

    private const int FAILOVER_MINIMUM_MEMBERS = 3;

    final public function __construct(protected Database $database) {}

    protected static function majorityOf(int $nodes): int
    {
        return intdiv($nodes, 2) + 1;
    }

    abstract public function maxReadOnlyNodes(): int;

    public function label(): string
    {
        return config()->string($this->key('label'), $this->database->engine);
    }

    public function icon(): ?string
    {
        $icon = config()->string($this->key('icon'), '');

        return $icon === '' ? null : asset($icon);
    }

    public function category(): string
    {
        return config()->string($this->key('category'), '');
    }

    public function toArray(): array
    {
        return [
            'value' => static::id(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'category' => $this->category(),
            'max_standbys' => $this->maxStandbys(),
            'lines' => array_values($this->versions()->map(fn (VersionLine $line): array => $line->toArray())->all()),
        ];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config($this->key($key), $default);
    }

    public function directory(): string
    {
        return config()->string($this->key('directory'), '') ?: "/srv/{$this->database->engine}";
    }

    public function systemUser(): string
    {
        return config()->string($this->key('system_user'), '') ?: $this->database->engine;
    }

    public function ports(): EnginePorts
    {
        return new EnginePorts(
            client: $this->nullableInt('port') ?? ClientPorts::CLIENT,
            pool: $this->nullableInt('pool_port'),
            peers: array_values(array_filter($this->peerPortsOf($this->versionLine()), is_int(...))),
        );
    }

    public function tls(): TlsSettings
    {
        $files = array_values(array_filter(config()->array($this->key('node_certificates'), []), is_string(...)));

        return new TlsSettings(
            files: $files === [] ? ['ca.crt', 'server.crt', 'server.key'] : $files,
            rsaKey: config()->boolean($this->key('rsa_server_key'), false),
            publicCertificate: config()->boolean($this->key('public_certificate'), false),
        );
    }

    public function maxStandbys(): int
    {
        return self::MAX_STANDBYS;
    }

    public function failoverHint(): string
    {
        return config()->string($this->key('failover_hint'), '');
    }

    public function supportsPersistence(): bool
    {
        return config()->boolean($this->key('persistence_choice'), false);
    }

    public function container(): string
    {
        return "databasify-{$this->database->engine}";
    }

    public function imageRepository(): string
    {
        $image = config()->string($this->key('image'), '');

        if ($image !== '') {
            return $image;
        }

        $repository = str_replace('{engine}', $this->database->engine, config()->string('databasify.images.repository'));

        return config()->string('databasify.images.registry')."/{$repository}";
    }

    public function versions(): Collection
    {
        $default = config()->string($this->key('default_line'), '');

        return collect(config()->array($this->key('lines'), []))
            ->filter(fn (mixed $line): bool => is_array($line) && is_string($line['value'] ?? null) && is_string($line['label'] ?? null))
            ->map(fn (array $line): VersionLine => new VersionLine($line['value'], $line['label'], $line['value'] === $default, ($line['recovery'] ?? true) !== false))
            ->sort(fn (VersionLine $a, VersionLine $b): int => version_compare($a->value, $b->value))
            ->values();
    }

    public function clientAddress(DatabaseNode $node): string
    {
        return $node->privateIp().':'.ClientPorts::CLIENT;
    }

    public function canFailover(Collection $nodes): bool
    {
        return $nodes->filter(fn (DatabaseNode $node): bool => $node->isMember())->count() >= self::FAILOVER_MINIMUM_MEMBERS;
    }

    public function scriptVariables(): array
    {
        return [];
    }

    protected function stopTimeout(): int
    {
        return config()->integer($this->key('stop_seconds'), 0);
    }

    protected function primarySetting(string $key, string $default = '0'): int
    {
        return (int) ($this->configured($this->database->primaryOrSeed())[$key] ?? $default);
    }

    /**
     * @return array<string, string>
     */
    protected function configured(DatabaseNode $node): array
    {
        return array_merge($this->tunedSettings($node->resources()), $this->database->settings->config);
    }

    private function key(string $name): string
    {
        return "database-provider.providers.{$this->database->engine}.{$name}";
    }

    /**
     * @return array<mixed>
     */
    private function peerPortsOf(string $line): array
    {
        $ports = config()->array($this->key('line_peer_ports'), [])[$line] ?? config()->array($this->key('peer_ports'), []);

        return is_array($ports) ? $ports : [];
    }

    private function nullableInt(string $name): ?int
    {
        $value = config($this->key($name));

        return is_int($value) ? $value : null;
    }
}
