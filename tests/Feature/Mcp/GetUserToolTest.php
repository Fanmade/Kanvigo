<?php

use App\Enums\Permission;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetUserTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a shared-project user with their email', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    $target = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);
    joinProject($project, [$viewer, $target]);

    KanvigoServer::actingAs($viewer)->tool(GetUserTool::class, ['id' => $target->public_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', $target->public_id)
            ->where('name', 'Dana')
            ->where('email', 'dana@example.com')
            ->etc());
});

it('withholds the email from an access-all viewer who shares no project', function () {
    $viewer = User::factory()->create();
    $viewer->syncPermissions([Permission::AccessAllProjects]);
    $target = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);

    KanvigoServer::actingAs($viewer)->tool(GetUserTool::class, ['id' => $target->public_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('name', 'Dana')
            ->where('email', null)
            ->etc());
});

it('errors for a user the viewer shares no project with', function () {
    $viewer = User::factory()->create();
    $target = User::factory()->create();

    KanvigoServer::actingAs($viewer)->tool(GetUserTool::class, ['id' => $target->public_id])
        ->assertHasErrors();
});

it('errors for an unknown user id', function () {
    $viewer = User::factory()->create();

    KanvigoServer::actingAs($viewer)->tool(GetUserTool::class, ['id' => 'nope'])
        ->assertHasErrors();
});
