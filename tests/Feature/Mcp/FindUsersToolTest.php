<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\FindUsersTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds a shared-project user by a name fragment, returning id and name only', function () {
    $caller = User::factory()->create();
    $target = User::factory()->create(['name' => 'Dana Scully', 'email' => 'dana@example.com']);
    joinProject(Project::factory()->create(), [$caller, $target]);

    KanvigoServer::actingAs($caller)->tool(FindUsersTool::class, ['query' => 'dana sc'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('truncated', false)
            ->has('users', 1, fn ($user) => $user
                ->where('id', $target->public_id)
                ->where('name', 'Dana Scully')
                ->missing('email')));
});

it('finds a shared-project user by an email fragment without echoing the email', function () {
    $caller = User::factory()->create();
    $target = User::factory()->create(['name' => 'Fox', 'email' => 'fox.mulder@fbi.gov']);
    joinProject(Project::factory()->create(), [$caller, $target]);

    KanvigoServer::actingAs($caller)->tool(FindUsersTool::class, ['query' => 'mulder@fbi'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('truncated', false)
            ->has('users', 1, fn ($user) => $user
                ->where('id', $target->public_id)
                ->where('name', 'Fox')
                ->missing('email')));
});

it('does not find a user the caller shares no project with', function () {
    $caller = User::factory()->create();
    joinProject(Project::factory()->create(), $caller);
    User::factory()->create(['name' => 'Stranger Danger']);

    KanvigoServer::actingAs($caller)->tool(FindUsersTool::class, ['query' => 'stranger'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('users', [])
            ->where('truncated', false));
});

it('caps the result set and flags truncation when many users match', function () {
    $caller = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $caller);
    joinProject($project, User::factory()->count(26)->create(['name' => 'Zed'])->all());

    KanvigoServer::actingAs($caller)->tool(FindUsersTool::class, ['query' => 'Zed'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('users', 25)
            ->where('truncated', true));
});

it('requires a query', function () {
    $caller = User::factory()->create();

    KanvigoServer::actingAs($caller)->tool(FindUsersTool::class, [])
        ->assertHasErrors();
});
