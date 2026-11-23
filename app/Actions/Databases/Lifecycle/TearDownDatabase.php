<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Domains\ClearDatabaseDns;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Actions\Databases\Recovery\DeleteArchive;
use App\Engines\Runtime\Runtime;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Dns\DnsCache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class TearDownDatabase
{
    private const int DNS_WAIT_SECONDS = 60;

    public function __construct(
        private RemoveNodeFirewall $firewall,
        private ClearDatabaseDns $dns,
        private DeleteArchive $archive,
        private Runtime $runtime,
        private DnsCache $cache,
    ) {}

    public function handle(Database $database, bool $eraseData, bool $force, bool $deleteBackups = false): void
    {
        $nodes = $database->nodesWithServers();
        $nodes->each(
            function (DatabaseNode $node) use ($database, $eraseData, $force): void {
                try {
                    $this->runtime->remove($node, $eraseData);
                    $this->firewall->handle($database, $node);
                } catch (Throwable $exception) {
                    throw_unless($force, $exception);
                    report($exception);
                }
            },
        );

        $recovery = $database->pointInTimeRecovery;

        if ($deleteBackups && $recovery !== null) {
            try {
                $this->archive->handle($recovery->storedArchive());
            } catch (Throwable $exception) {
                $database->seed()->server->logFailure('Delete point-in-time backups', "Could not delete the backups under {$recovery->path}, delete them by hand: {$exception->getMessage()}", $database);
            }
        }

        $this->cache->databaseLock($database)->block(self::DNS_WAIT_SECONDS, function () use ($database): void {
            try {
                $this->dns->handle($database);
            } catch (Throwable $exception) {
                $database->seed()->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records, delete them by hand: {$exception->getMessage()}", $database);
            }

            DB::transaction(fn () => $database->delete());
        });
    }
}
