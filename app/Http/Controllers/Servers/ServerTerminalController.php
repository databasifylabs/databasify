<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\OpenTerminal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\OpenTerminalRequest;
use App\Http\Requests\Servers\TerminalInputRequest;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\TerminalSession;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ServerTerminalController extends Controller
{
    public function store(OpenTerminalRequest $request, Server $server, #[CurrentUser] User $user, OpenTerminal $open): JsonResponse
    {
        $session = $open->handle($server, $user, $request->columns(), $request->rows());

        return response()->json(['id' => $session->id], 201);
    }

    public function input(TerminalInputRequest $request, Server $server, string $terminal, #[CurrentUser] User $user): Response
    {
        abort_unless($this->sessionFor($server, $terminal, $user)->push($request->typed()), 410);

        return response()->noContent();
    }

    public function destroy(Server $server, string $terminal, #[CurrentUser] User $user): Response
    {
        $this->sessionFor($server, $terminal, $user)->close();

        return response()->noContent();
    }

    private function sessionFor(Server $server, string $terminal, User $user): TerminalSession
    {
        $session = new TerminalSession($terminal);
        abort_unless($session->isFor($server, $user), 404);

        return $session;
    }
}
