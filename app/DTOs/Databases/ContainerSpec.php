<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ContainerSpec
{
    public const int ENGINE_UID = 64999;

    public const string ENGINE_HOST_USER = 'databasify-engine';

    private const int STOP_GRACE_SECONDS = 60;

    private const int OPEN_FILES_LIMIT = 65536;

    public function __construct(
        public string $name,
        public EngineImage $image,
        public string $directory,
        public int $stopTimeoutSeconds,
        public string $databaseKey,
        public string $nodeKey,
    ) {}

    public static function for(Database $database, DatabaseNode $node, ?EngineImage $image = null): self
    {
        $engine = $database->provider();

        return new self(
            name: $engine->container(),
            image: $image ?? $database->image(),
            directory: $engine->directory(),
            stopTimeoutSeconds: $engine->processes($node)->shutdownSeconds() + self::STOP_GRACE_SECONDS,
            databaseKey: $database->dataKey(),
            nodeKey: $database->nodeKeyOf($node),
        );
    }

    public function toRunCommand(): string
    {
        $reference = $this->image->reference();

        return collect([
            'docker', 'run', '-d',
            '--name', $this->name,
            '--network', 'host',
            '--ipc', 'host',
            '--security-opt', 'label=disable',
            '--restart', 'unless-stopped',
            '--stop-timeout', (string) $this->stopTimeoutSeconds,
            '--ulimit', 'nofile='.self::OPEN_FILES_LIMIT.':'.self::OPEN_FILES_LIMIT,
            '-v', "{$this->directory}:{$this->directory}",
            '--label', "io.databasify.database={$this->databaseKey}",
            '--label', "io.databasify.node={$this->nodeKey}",
            '-e', "DATABASIFY_IMAGE={$reference}",
            $reference,
        ])->map(self::shellWord(...))->implode(' ');
    }

    private static function shellWord(string $word): string
    {
        return preg_match('/^[A-Za-z0-9@%+=:,.\/_-]+$/', $word) === 1 ? $word : escapeshellarg($word);
    }
}
