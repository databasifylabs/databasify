<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\ConfigFile;
use App\Engines\Data\Member;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\EngineClient;
use App\Engines\Payload;
use App\Engines\Requests\FileGet;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\NodeConfigure as NodeConfigureReply;
use App\Engines\Settings\SettingsCatalog;
use App\Models\DatabaseNode;

final readonly class Configuration
{
    public const string TUNED_STATE = 'conf/tuned.json';

    private const string REQUEST_STATE = 'conf/request.json';

    private const string POOL_SIZE_PLACEHOLDER = '__DEFAULT_POOL_SIZE__';

    private const int CONFIGURE_TIMEOUT = 300;

    public function __construct(
        private EngineScripts $scripts,
        private EngineClient $engine,
        private Accounts $accounts,
    ) {}

    public function configure(DatabaseNode $node, NodeConfigure $request): NodeConfigureReply
    {
        $line = $node->database->version_line;
        $catalog = $this->catalogOf($node);
        $catalog->check($request->settings);
        $saved = $this->savedTunedOf($node);
        $tuned = $saved ?? $catalog->tuned($this->selfOf($request));
        $files = PostgresFiles::of($this->withSettings($request, $catalog->effective($tuned, $request->settings)), $line, self::POOL_SIZE_PLACEHOLDER);

        $reply = $this->scripts->run($node, 'postgres/nodes/configure', $this->accounts->dataFor($node), timeout: self::CONFIGURE_TIMEOUT, env: [
            'REQUEST_JSON' => json_encode($this->payloadOf($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'TUNED_JSON' => $saved === null ? $this->tunedJsonOf($tuned) : '',
            'FILES' => json_encode(array_map($this->entryOf(...), $files->files), JSON_THROW_ON_ERROR),
            'MANIFEST' => base64_encode($files->manifest),
            'CLUSTERED' => $request->clustered ? 'true' : 'false',
            'READ_ONLY' => $request->readOnly ? 'true' : 'false',
            'ARCHIVE' => $request->archive === null ? 'false' : 'true',
            'LINE' => $line,
            'IP' => $request->ip,
            'MEMBERS' => json_encode(array_map(fn (Member $member): array => $member->toPayload(), $request->members), JSON_THROW_ON_ERROR),
        ]);

        return new NodeConfigureReply($reply->json()->bool('changed'));
    }

    /**
     * @return array<string, string>|null
     */
    public function savedTunedOf(DatabaseNode $node): ?array
    {
        $file = $this->engine->send($node, new FileGet(self::TUNED_STATE));

        if (! $file->exists || $file->content === null) {
            return null;
        }

        $saved = json_decode($file->content, true);

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

    public function selfOf(NodeConfigure $request): Member
    {
        foreach ($request->members as $member) {
            if ($member->ip === $request->ip) {
                return $member;
            }
        }

        return new Member($request->ip, 0, false, 1, 1024, 10);
    }

    /**
     * @param  array<string, string>  $tuned
     */
    public function tunedJsonOf(array $tuned): string
    {
        ksort($tuned, SORT_STRING);

        return json_encode((object) $tuned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function catalogOf(DatabaseNode $node): SettingsCatalog
    {
        return PostgresSettings::catalogFor($node->database->version_line);
    }

    public function savedRequestOf(DatabaseNode $node): ?NodeConfigure
    {
        $file = $this->engine->send($node, new FileGet(self::REQUEST_STATE));

        if (! $file->exists || $file->content === null) {
            return null;
        }

        return $this->requestFrom(Payload::decode($file->content));
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function withSettings(NodeConfigure $request, array $settings): NodeConfigure
    {
        return new NodeConfigure($request->clustered, $request->readOnly, $request->ip, $request->databaseId, $request->members, $settings, $request->secrets, $request->archive, $request->primary);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(NodeConfigure $request): array
    {
        $settings = $request->settings;
        ksort($settings, SORT_STRING);

        return [...$request->toPayload(), 'settings' => (object) $settings];
    }

    /**
     * @return array{path: string, mode: string, content: string}
     */
    private function entryOf(ConfigFile $file): array
    {
        return ['path' => $file->path, 'mode' => decoct($file->mode), 'content' => base64_encode($file->content)];
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
