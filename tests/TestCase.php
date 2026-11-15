<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Ssh\ServerConnection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Inertia;
use LogicException;
use Tests\Support\FakeServerConnection;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Inertia::disableSsr();
        $this->app->instance(ServerConnection::class, new FakeServerConnection);
    }

    protected function ssh(): FakeServerConnection
    {
        $connection = $this->app->make(ServerConnection::class);

        return $connection instanceof FakeServerConnection ? $connection : throw new LogicException('The fake SSH connection is not bound.');
    }
}
