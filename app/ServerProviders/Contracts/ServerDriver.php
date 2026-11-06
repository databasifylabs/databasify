<?php

declare(strict_types=1);

namespace App\ServerProviders\Contracts;

use App\DTOs\Forms\DynamicForm;
use App\DTOs\Providers\NewProviderServer;
use App\DTOs\Providers\ProviderNetwork;
use App\DTOs\Providers\ProviderPlan;
use App\DTOs\Providers\ProviderRegion;
use App\DTOs\Providers\ProviderServer;
use App\Enums\Servers\OperatingSystem;

interface ServerDriver
{
    public static function id(): string;

    public function label(): string;

    public function icon(): ?string;

    public function form(): DynamicForm;

    public function serverLabel(): string;

    public function sshUser(): string;

    public function image(OperatingSystem $os): int|string|null;

    public function config(string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public function verify(): void;

    /**
     * @return list<ProviderRegion>
     */
    public function regions(): array;

    /**
     * @return list<ProviderPlan>
     */
    public function plans(string $region): array;

    /**
     * @return list<ProviderNetwork>
     */
    public function networks(string $region): array;

    public function createNetwork(string $region, string $name): ProviderNetwork;

    public function deleteNetwork(string $externalId): void;

    public function findKey(string $publicKey): ?string;

    public function createKey(string $name, string $publicKey): string;

    public function createServer(NewProviderServer $server): string;

    public function findServer(string $id): ProviderServer;

    public function deleteServer(string $id): void;
}
