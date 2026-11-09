<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

use function Pest\Laravel\withHeaders;

uses(RefreshDatabase::class);

/**
 * Call an MCP tool over the real /mcp HTTP endpoint authenticated by a named
 * personal access token, returning the JSON-RPC response.
 */
function callMcpToolAs(User $user, string $tokenName, string $tool, array $arguments)
{
    $token = $user->createToken($tokenName, ['read', 'write'])->plainTextToken;

    return withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
}

it('attributes an MCP-created task to the personal access token name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    callMcpToolAs($user, 'Claude', 'create-task-tool', [
        'reference' => $project->short_name,
        'title' => 'Created over MCP',
    ])->assertOk();

    $task = Task::where('title', 'Created over MCP')->firstOrFail();

    expect($task->activities()->where('action', 'created')->first()->token_name)->toBe('Claude');
});

it('attributes an MCP status update to the personal access token name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::Planned)->create();

    callMcpToolAs($user, 'Claude', 'update-task-tool', [
        'reference' => $task->reference,
        'status' => Status::Done->value,
    ])->assertOk();

    expect($task->activities()->where('action', 'status_changed')->first()->token_name)->toBe('Claude');
});

/**
 * Call an MCP tool over the real /mcp HTTP endpoint as an OAuth connection
 * (Passport guard), optionally of a specific OAuth client.
 */
function callMcpToolViaOAuthAs(User $user, ?Client $client, string $tool, array $arguments)
{
    Passport::actingAs($user, ['mcp:use'], 'api', $client);

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

it('attributes an MCP change over OAuth to the OAuth client name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::Planned)->create();

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude Desktop',
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
    );

    callMcpToolViaOAuthAs($user, $client, 'update-task-tool', [
        'reference' => $task->reference,
        'status' => Status::Done->value,
    ])->assertOk();

    $activity = $task->activities()->where('action', 'status_changed')->sole();

    expect($activity->token_name)->toBe('Claude Desktop')
        ->and($activity->user_id)->toBe($user->id);
});

it('never records an OAuth change as a manual one, even without a resolvable client', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    callMcpToolViaOAuthAs($user, null, 'create-task-tool', [
        'reference' => $project->short_name,
        'title' => 'Created over OAuth',
    ])->assertOk();

    $task = Task::where('title', 'Created over OAuth')->firstOrFail();

    expect($task->activities()->where('action', 'created')->sole()->token_name)->toBe('OAuth client');
});

it('records no token name for an equivalent web-session action', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    // A normal logged-in user (no personal access token) — the control case.
    $this->actingAs($user);
    seedActivity($task, 'status_changed', 'status', 'planned', 'done');

    expect($task->activities()->where('action', 'status_changed')->first()->token_name)->toBeNull();
});
