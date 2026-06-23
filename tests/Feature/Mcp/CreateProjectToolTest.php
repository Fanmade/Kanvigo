<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\CreateProjectTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('creates a project and adds the user as a member with a write token', function () {
    $user = User::factory()->canCreateProjects()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateProjectTool::class, [
        'title' => 'New Project',
        'short_name' => 'NEW',
    ])
        ->assertOk()
        ->assertSee('NEW');

    assertDatabaseHas('projects', ['short_name' => 'NEW', 'title' => 'New Project']);
    assertDatabaseHas('project_user', ['user_id' => $user->id, 'role' => 'owner']);
});

it('uppercases the provided short_name', function () {
    $user = User::factory()->canCreateProjects()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateProjectTool::class, [
        'title' => 'New Project',
        'short_name' => 'low',
    ])->assertOk();

    assertDatabaseHas('projects', ['short_name' => 'LOW']);
});

it('denies project creation without the create-projects permission', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateProjectTool::class, [
        'title' => 'New Project',
        'short_name' => 'NEW',
    ])->assertHasErrors();
});

it('denies project creation with a read-only token', function () {
    $user = User::factory()->canCreateProjects()->create();
    Sanctum::actingAs($user, ['read']);

    KanvigoServer::tool(CreateProjectTool::class, [
        'title' => 'New Project',
        'short_name' => 'NEW',
    ])->assertHasErrors();
});

it('rejects a duplicate short_name', function () {
    $user = User::factory()->canCreateProjects()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    Project::factory()->create(['short_name' => 'DUP']);

    KanvigoServer::tool(CreateProjectTool::class, [
        'title' => 'Another',
        'short_name' => 'DUP',
    ])->assertHasErrors();
});
