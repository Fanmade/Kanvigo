<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetCurrentUserTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the acting user\'s own id, name and email', function () {
    $user = User::factory()->create(['name' => 'Sam Carter', 'email' => 'sam@example.com']);

    KanvigoServer::actingAs($user)->tool(GetCurrentUserTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', $user->public_id)
            ->where('name', 'Sam Carter')
            ->where('email', 'sam@example.com')
            ->etc());
});

it('resolves the id that set-assignees expects (assign a task to yourself)', function () {
    $user = User::factory()->create();

    KanvigoServer::actingAs($user)->tool(GetCurrentUserTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('id', $user->public_id)->etc());
});
