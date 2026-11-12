<?php

declare(strict_types=1);

namespace App\Engines\Drivers;

use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\ConfigFile;
use App\Engines\Data\Member;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\EngineClient;
use App\Engines\Payload;
use App\Engines\Requests\FileGet;
use App\Engines\Requests\FilePut;
use App\Engines\Requests\NodeConfigure;
use App\Models\DatabaseNode;

final readonly class NodeState
{
    public function __construct(private EngineClient $engine) {}

    /**
     * @return array<string, string>|null
     */
    public function tunedOf(DatabaseNode $node, string $path): ?array
    {
        $content = $this->contentOf($node, $path);
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

    public function requestOf(DatabaseNode $node, string $path): ?NodeConfigure
    {
        $content = $this->contentOf($node, $path);

        return $content === null ? null : $this->requestFrom(Payload::decode($content));
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public function saveTuned(DatabaseNode $node, string $path, array $tuned): void
    {
        $this->engine->send($node, new FilePut($path, self::tunedJsonOf($tuned), 0o600, null));
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public static function tunedJsonOf(array $tuned): string
    {
        ksort($tuned, SORT_STRING);

        return json_encode((object) $tuned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function selfOf(NodeConfigure $request): Member
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
    public static function withSettings(NodeConfigure $request, array $settings): NodeConfigure
    {
        return new NodeConfigure($request->clustered, $request->readOnly, $request->ip, $request->databaseId, $request->members, $settings, $request->secrets, $request->archive, $request->primary);
    }

    public static function requestJsonOf(NodeConfigure $request): string
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

    private function contentOf(DatabaseNode $node, string $path): ?string
    {
        $file = $this->engine->send($node, new FileGet($path));

        return $file->exists ? $file->content : null;
    }

    private function requestFrom(Payload $saved): NodeConfigure
    {
        $secrets = $saved->object('secrets');
        $archive = $saved->nullableObject('archive');

        return new NodeConfigure(
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
