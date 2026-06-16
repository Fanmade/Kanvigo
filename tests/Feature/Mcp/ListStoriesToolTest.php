<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\ListStoriesTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the stories of a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create(['title' => 'First Story']);

    KanbrioServer::actingAs($user)->tool(ListStoriesTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee($story->reference)
        ->assertSee('First Story');
});

it('denies listing stories of a project the user is not a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    Story::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(ListStoriesTool::class, ['short_name' => 'ABC'])
        ->assertHasErrors();
});
