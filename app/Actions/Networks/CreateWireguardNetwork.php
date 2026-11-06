<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Enums\Servers\NetworkKind;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final readonly class CreateWireguardNetwork
{
    private const int LOCK_SECONDS = 10;

    public function __construct(private AllocateNetworkBlock $blocks) {}

    /**
     * @param  Collection<int, Server>  $memberServers
     */
    public function handle(int $blockPrefix, Collection $memberServers): Network
    {
        $lock = Cache::lock('networks.wireguard.create', self::LOCK_SECONDS);
        $lock->block(self::LOCK_SECONDS);

        try {
            return $this->create($blockPrefix, $memberServers);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Collection<int, Server>  $memberServers
     */
    private function create(int $blockPrefix, Collection $memberServers): Network
    {
        return Network::query()->create([
            'name' => Network::freeName(),
            'cidr' => $this->blocks->handle($blockPrefix, $memberServers),
            'kind' => NetworkKind::Wireguard,
            'listen_port' => Network::LISTEN_PORT,
        ]);
    }
}
