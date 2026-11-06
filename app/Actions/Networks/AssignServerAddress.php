<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\DTOs\Servers\NetworkAssignment;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Models\Server;
use App\Services\Networks\WireguardAddressAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class AssignServerAddress
{
    public function __construct(
        private ResolvePrivateNetwork $networks,
        private WireguardAddressAllocator $addresses,
    ) {}

    public function handle(Server $server, PrivateNetworkChoice $choice): NetworkAssignment
    {
        DB::beginTransaction();

        try {
            $assignment = $this->assignedTo($server, $this->networks->handle($choice, $server));
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return $assignment;
    }

    private function assignedTo(Server $server, NetworkAssignment $assignment): NetworkAssignment
    {
        if ($this->savedOn($server, $assignment)) {
            return $assignment;
        }

        $retry = $this->freshAddressFor($server, $assignment);

        if ($this->savedOn($server, $retry)) {
            return $retry;
        }

        throw ValidationException::withMessages(['private_network' => 'That address was just taken, try again.']);
    }

    private function savedOn(Server $server, NetworkAssignment $assignment): bool
    {
        DB::beginTransaction();

        try {
            $server->fill($assignment->toRow())->save();
        } catch (Throwable $exception) {
            DB::rollBack();

            if ($exception instanceof UniqueConstraintViolationException && $this->clashesOnAddress($exception, $assignment)) {
                return false;
            }

            throw $exception;
        }

        DB::commit();

        return true;
    }

    private function freshAddressFor(Server $server, NetworkAssignment $taken): NetworkAssignment
    {
        if ($taken->network === null || ! $taken->network->isWireguard()) {
            return $taken;
        }

        return new NetworkAssignment($taken->network, $this->addresses->next($taken->network, $server));
    }

    private function clashesOnAddress(UniqueConstraintViolationException $exception, NetworkAssignment $assignment): bool
    {
        if ($assignment->network === null || $assignment->privateIp === null) {
            return false;
        }

        return str_contains((string) $exception->getPrevious()?->getMessage(), 'private_ip');
    }
}
