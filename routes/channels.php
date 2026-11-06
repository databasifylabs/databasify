<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Ssh\TerminalSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id): bool => $user->id === $id);

Broadcast::channel('server-logs.{log}', fn (User $user): bool => true);

Broadcast::channel('terminals.{terminal}', fn (User $user, string $terminal): bool => (new TerminalSession($terminal))->attach($user));
