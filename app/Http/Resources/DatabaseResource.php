<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Databases\ConfigField;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Http\Request;

/**
 * @mixin Database
 */
class DatabaseResource extends DatabaseListResource
{
    public const array RELATIONS = ['dnsZone', 'nodes.server.network', 'users', 'schemas', 'recentMigrations', 'trustedSources', 'openAlerts.node.server', 'connectionPools', 'pointInTimeRecovery.storageProvider'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $engine = $this->provider();

        return [
            ...parent::toArray($request),
            'engine' => $this->engine,
            'version_line' => $this->version_line,
            'version_label' => $this->versionLabel(),
            'retune_needed' => $this->retune_needed_at !== null,
            'maintenance' => $this->maintenanceWindow()->toArray(now()),
            'domain' => $this->hostnames()?->primary->public,
            'ca_certificate' => $this->clientCaCertificate(),
            'dns_zone_id' => $this->dns_zone_id,
            'hostnames' => $this->hostnamesArray(),
            'network' => $this->networkOfNodes(),
            'port' => $engine->ports()->client,
            'pool_port' => $engine->ports()->pool,
            'supports_users' => $engine->accounts() !== null,
            'password_encryptions' => $this->encryptionChoicesOf($this->passwordEncryptions()),
            'migration_source' => $this->engine()->migrationSource?->toArray(),
            'connection' => $engine->connection()->toArray(),
            'failover_hint' => $engine->failoverHint(),
            'automatic_failover' => $engine->canFailover($this->nodes),
            'supports_point_in_time_recovery' => $this->archivesForRecovery(),
            'point_in_time_recovery_unavailable' => $this->recoveryUnavailableMessage(),
            'restored_from' => $this->settings->restore === null ? null : ['database' => $this->settings->restore->database, 'target' => $this->settings->restore->target->toIso8601String()],
            'offers_persistence' => $engine->supportsPersistence(),
            'persistence' => $this->settings->persistence->value,
            'services' => $this->processNames(),
            'config' => $this->settings->config,
            'tuned_config' => $this->tunedConfig(),
            'config_fields' => array_map(fn (ConfigField $field): array => $field->toArray(), $this->configFields()),
            'endpoints' => $this->endpointsAmong($this->nodes)->toArray(),
            'users' => DatabaseUserResource::collection($this->users),
            'schemas' => DatabaseSchemaResource::collection($this->schemas),
            'migrations' => DatabaseMigrationResource::collection($this->recentMigrations),
            'outbound_ips' => $this->nodes->map(fn (DatabaseNode $node): string => $node->server->address())->all(),
            'alerts' => DatabaseAlertResource::collection($this->openAlerts),
            'pools' => DatabasePoolResource::collection($this->connectionPools),
            'trusted_sources' => TrustedSourceResource::collection($this->trustedSources),
            'max_standbys' => $engine->maxStandbys(),
            'max_read_only_nodes' => $engine->maxReadOnlyNodes(),
        ];
    }

    /**
     * @param  list<string>  $encryptions
     * @return list<array{value: string, label: string}>
     */
    private function encryptionChoicesOf(array $encryptions): array
    {
        if (count($encryptions) < 2) {
            return [];
        }

        return array_map(fn (string $encryption): array => ['value' => $encryption, 'label' => $encryption], $encryptions);
    }

    private function networkOfNodes(): ?NetworkResource
    {
        $network = $this->nodes->first()?->server->network;

        return $network === null ? null : new NetworkResource($network);
    }

    /**
     * @return array{primary: string, private: string, replica: string, private_replica: string}|null
     */
    private function hostnamesArray(): ?array
    {
        $hostnames = $this->hostnames();

        return $hostnames === null ? null : [
            'primary' => $hostnames->primary->public,
            'private' => $hostnames->primary->private,
            'replica' => $hostnames->replica->public,
            'private_replica' => $hostnames->replica->private,
        ];
    }
}
