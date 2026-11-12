<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Responses\Operation;
use App\Engines\Scripts\ScriptFailure;
use App\Engines\Scripts\ScriptResult;
use App\Engines\Scripts\ScriptView;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LogicException;

final class LocalScriptRunner implements EngineScripts
{
    private const string CLIENT = <<<'SH'
        #!/bin/bash
        sql=$(cat)
        printf '%s\n-- end --\n' "$sql" >> "$STUB_DIR/sql.log"
        for rule in "$STUB_DIR"/rules/*; do
            [ -e "$rule" ] || continue
            if grep -qF -- "$(head -n 1 "$rule")" <<< "$sql"; then
                reply=$(tail -n +2 "$rule")
                if [[ $reply == ERROR\ * ]]; then
                    printf '%s\n' "$reply" >&2
                    exit 1
                fi
                printf '%s\n' "$reply"
                exit 0
            fi
        done
        SH;

    private const string PASS_THROUGH = "#!/bin/bash\nshift\nexec \"\$@\"\n";

    private const string RECORDER = "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$STUB_DIR/commands.log\"\n";

    /** @var array<string, string> */
    private array $replies = [];

    /** @var list<string> */
    private array $statements = [];

    /** @var list<string> */
    private array $commands = [];

    public function __construct(private readonly ScriptView $views) {}

    public function reply(string $whenSqlContains, string $output): self
    {
        $this->replies[$whenSqlContains] = $output;

        return $this;
    }

    public function run(DatabaseNode $node, string $script, array $data = [], ?string $user = null, int $timeout = 120, ?string $log = null, array $env = []): ScriptResult
    {
        $directory = sys_get_temp_dir().'/local-script-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists("{$directory}/bin");
        File::ensureDirectoryExists("{$directory}/rules");
        $this->writeStubs($directory);

        $run = Process::input($this->views->render($node, $script, $data))
            ->env([...$env, 'PATH' => "{$directory}/bin:".getenv('PATH'), 'STUB_DIR' => $directory])
            ->run(['bash', '-euo', 'pipefail', '-s']);

        $this->statements = [...$this->statements, ...$this->logged("{$directory}/sql.log", "\n-- end --\n")];
        $this->commands = [...$this->commands, ...$this->logged("{$directory}/commands.log", "\n")];
        File::deleteDirectory($directory);

        if (! $run->successful()) {
            throw ScriptFailure::of($script, (int) $run->exitCode(), $run->output(), $run->errorOutput());
        }

        return new ScriptResult($script, $run->output());
    }

    public function start(DatabaseNode $node, string $key, string $script, array $data = [], ?string $user = null, array $env = []): Operation
    {
        throw new LogicException('Tasks do not run locally.');
    }

    public function phaseOf(DatabaseNode $node, string $key): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    private function writeStubs(string $directory): void
    {
        foreach (['mysql', 'mariadb'] as $client) {
            File::put("{$directory}/bin/{$client}", self::CLIENT."\n");
        }

        File::put("{$directory}/bin/timeout", self::PASS_THROUGH);
        File::put("{$directory}/bin/databasify", self::RECORDER);

        foreach (File::files("{$directory}/bin") as $stub) {
            chmod($stub->getPathname(), 0755);
        }

        $index = 0;

        foreach ($this->replies as $needle => $output) {
            File::put(sprintf('%s/rules/%03d', $directory, $index++), "{$needle}\n{$output}");
        }
    }

    /**
     * @return list<string>
     */
    private function logged(string $path, string $separator): array
    {
        if (! File::exists($path)) {
            return [];
        }

        return array_values(array_filter(explode($separator, File::get($path)), fn (string $entry): bool => trim($entry) !== ''));
    }
}
