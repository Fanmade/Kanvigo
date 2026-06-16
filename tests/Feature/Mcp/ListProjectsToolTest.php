<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\ListProjectsTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only the projects the user is a member of', function () {
    $user = User::factory()->create();
    $member = Project::factory()->withMembers([$user])->create(['title' => 'Member Project']);
    $foreign = Project::factory()->create(['title' => 'Foreign Project']);

    KanbrioServer::actingAs($user)->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee($member->short_name)
        ->assertSee('Member Project')
        ->assertDontSee($foreign->short_name)
        ->assertDontSee('Foreign Project');
});

it('returns no projects for a user who is a member of none', function () {
    $user = User::factory()->create();
    Project::factory()->create();

    KanbrioServer::actingAs($user)->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee('"projects":[]');
});
