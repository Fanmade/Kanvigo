<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetProjectTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee('ABC')
        ->assertSee($story->reference);
});

it('denies access to a project the user is not a member of', function () {
    $user = User::factory()->create();
    Project::factory()->create(['short_name' => 'ABC']);

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertHasErrors();
});

it('returns an error for a project that does not exist', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'NOPE'])
        ->assertHasErrors();
});

it('requires the short_name argument', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class)
        ->assertHasErrors();
});
