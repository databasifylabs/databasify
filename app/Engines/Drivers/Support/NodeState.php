<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Support;

use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\ConfigFile;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Payload;
use App\Engines\Settings\SettingsCatalog;

final readonly class NodeState
{
    /**
     * @return array<string, string>|null
     */
    public function tunedOf(AbstractEngineDriver $driver, string $path): ?array
    {
        $content = $driver->getFile($path);
        $saved = $content === null ? null : json_decode($content, true);

        if (! is_array($saved)) {
            return null;
        }

        $tuned = [];

        foreach ($saved as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $tuned[$key] = $value;
            }
        }

        return $tuned;
    }

    /**
     * @return array<string, string>
     */
    public function tunedOrNewOf(AbstractEngineDriver $driver, string $path, SettingsCatalog $catalog, NodeConfig $request): array
    {
        $saved = $this->tunedOf($driver, $path);

        if ($saved !== null) {
            return $saved;
        }

        $tuned = $catalog->tuned(self::selfOf($request));
        $this->saveTuned($driver, $path, $tuned);

        return $tuned;
    }

    public function requestOf(AbstractEngineDriver $driver, string $path): ?NodeConfig
    {
        $content = $driver->getFile($path);

        return $content === null ? null : $this->requestFrom(Payload::decode($content));
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public function saveTuned(AbstractEngineDriver $driver, string $path, array $tuned): void
    {
        $driver->putFile($path, self::tunedJsonOf($tuned), 0o600);
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public static function tunedJsonOf(array $tuned): string
    {
        ksort($tuned, SORT_STRING);

        return json_encode((object) $tuned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function selfOf(NodeConfig $request): Member
    {
        foreach ($request->members as $member) {
            if ($member->ip === $request->ip) {
                return $member;
            }
        }

        return new Member($request->ip, 0, false, 1, 1024, 10);
    }

    /**
     * @param  array<string, string>  $settings
     */
    public static function withSettings(NodeConfig $request, array $settings): NodeConfig
    {
        return new NodeConfig($request->clustered, $request->readOnly, $request->ip, $request->databaseId, $request->members, $settings, $request->secrets, $request->archive, $request->primary);
    }

    public static function requestJsonOf(NodeConfig $request): string
    {
        $settings = $request->settings;
        ksort($settings, SORT_STRING);

        return json_encode([...$request->toPayload(), 'settings' => (object) $settings], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<ConfigFile>  $files
     */
    public static function filesJsonOf(array $files): string
    {
        return json_encode(array_map(fn (ConfigFile $file): array => ['path' => $file->path, 'mode' => decoct($file->mode), 'content' => base64_encode($file->content)], $files), JSON_THROW_ON_ERROR);
    }

    private function requestFrom(Payload $saved): NodeConfig
    {
        $secrets = $saved->object('secrets');
        $archive = $saved->nullableObject('archive');

        return new NodeConfig(
            clustered: $saved->bool('clustered'),
            readOnly: $saved->bool('readOnly'),
            ip: $saved->string('ip'),
            databaseId: $saved->string('databaseId'),
            members: array_map(fn (Payload $member): Member => new Member($member->string('ip'), $member->int('position'), $member->bool('voting'), $member->int('cpus'), $member->int('memoryMb'), $member->int('diskGb')), $saved->objects('members')),
            settings: $saved->stringMap('settings'),
            secrets: new Secrets($secrets->nullableString('admin'), $secrets->nullableString('replication'), $secrets->nullableString('agent')),
            archive: $archive === null ? null : $this->archiveFrom($archive),
            primary: $saved->nullableString('primary'),
        );
    }

    private function archiveFrom(Payload $archive): ArchiveSettings
    {
        $storage = $archive->object('storage');

        return new ArchiveSettings(
            new Storage($storage->string('endpoint'), $storage->string('bucket'), $storage->string('region'), $storage->string('accessKey'), $storage->string('secretKey'), $storage->bool('pathStyle'), $storage->string('path')),
            $archive->int('retentionDays'),
            $archive->int('backupHour'),
        );
    }
}
