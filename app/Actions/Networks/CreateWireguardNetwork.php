<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Enums\Servers\NetworkKind;
use App\Models\Network;
use App\Models\Server;
use App\Support\Networks\Cidr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final readonly class CreateWireguardNetwork
{
    /**
     * @param  Collection<int, Server>  $memberServers
     */
    public function handle(int $blockPrefix, Collection $memberServers): Network
    {
        $taken = Network::query()->withoutProject()->get(['cidr'])->toBase()->map(fn (Network $network): string => $network->cidr)
            ->merge($memberServers->flatMap(fn (Server $server): array => $server->interface_subnets?->all() ?? []));
        $block = Cidr::blocksIn(Network::WIREGUARD_POOL, $blockPrefix)->first(fn (string $block): bool => $taken->doesntContain(fn (string $range): bool => Cidr::overlaps($block, $range)))
            ?? throw ValidationException::withMessages(['network' => "No free /{$blockPrefix} block is left in ".Network::WIREGUARD_POOL.'. Choose a smaller block size.']);

        return Network::query()->create([
            'name' => Network::freeName(),
            'cidr' => $block,
            'kind' => NetworkKind::Wireguard,
            'listen_port' => Network::LISTEN_PORT,
        ]);
    }
}
