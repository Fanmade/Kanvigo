<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\UpdateStoryTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('updates a story title and description', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create(['title' => 'Old']);

    KanbrioServer::tool(UpdateStoryTool::class, [
        'reference' => $story->reference,
        'title' => 'New title',
        'description' => 'New description',
    ])
        ->assertOk()
        ->assertSee('New title');

    assertDatabaseHas('stories', ['id' => $story->id, 'title' => 'New title', 'description' => 'New description']);
});

it('errors when no fields are provided to update', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(UpdateStoryTool::class, ['reference' => $story->reference])
        ->assertHasErrors();
});

it('denies updating a story the user cannot access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(UpdateStoryTool::class, [
        'reference' => $story->reference,
        'title' => 'New title',
    ])->assertHasErrors();
});

it('denies updating a story with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(UpdateStoryTool::class, [
        'reference' => $story->reference,
        'title' => 'New title',
    ])->assertHasErrors();
});
