<?php

declare(strict_types=1);

use App\Actions\Projects\MoveToProject;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function runningDatabase(): Database
{
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $database->update(['status' => DatabaseStatus::Ready]);

    return $database;
}

it('hides the star prompt until a database is running', function () {
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')->update(['status' => DatabaseStatus::Creating]);

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('showStarPrompt', false));
});

it('shows the star prompt once a database is running', function () {
    runningDatabase();

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('showStarPrompt', true));
});

it('shows the star prompt when the running database belongs to another project', function () {
    resolve(MoveToProject::class)->handle(runningDatabase(), Project::factory()->create());

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('databases.data', 0)
        ->where('showStarPrompt', true));
});

it('hides the star prompt once it was dismissed', function () {
    $this->freezeSecond();
    runningDatabase();

    $this->from(projectUrl('/dashboard'))->delete(route('repository.star-prompt.destroy'))->assertRedirect(projectUrl('/dashboard'));

    expect(Setting::query()->named('star_prompt_dismissed_at')->value('value'))->toBe(now()->toIso8601String());
    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('showStarPrompt', false));
});

it('keeps dismissing the star prompt behind the login', function () {
    auth()->logout();

    $this->delete(route('repository.star-prompt.destroy'))->assertRedirect(route('login'));

    expect(Setting::query()->count())->toBe(0);
});
