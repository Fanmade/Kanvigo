<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\CreateStoryTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('creates a story in a project the user is a member of', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanbrioServer::tool(CreateStoryTool::class, [
        'short_name' => 'ABC',
        'title' => 'First story',
    ])
        ->assertOk()
        ->assertSee('ABC1');

    assertDatabaseHas('stories', ['project_id' => $project->id, 'title' => 'First story']);
});

it('denies creating a story in a project the user cannot access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    Project::factory()->create(['short_name' => 'ABC']);

    KanbrioServer::tool(CreateStoryTool::class, [
        'short_name' => 'ABC',
        'title' => 'First story',
    ])->assertHasErrors();
});

it('denies creating a story with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanbrioServer::tool(CreateStoryTool::class, [
        'short_name' => 'ABC',
        'title' => 'First story',
    ])->assertHasErrors();
});

it('requires a title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanbrioServer::tool(CreateStoryTool::class, ['short_name' => 'ABC'])
        ->assertHasErrors();
});
