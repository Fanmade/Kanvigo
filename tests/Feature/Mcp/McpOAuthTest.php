<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

uses(RefreshDatabase::class);

/**
 * Call an MCP tool over the /mcp HTTP endpoint as the given OAuth-authenticated
 * user, returning the JSON-RPC response.
 */
function callMcpToolViaOAuth(User $user, string $tool, array $arguments = [])
{
    Passport::actingAs($user, ['mcp:use']);

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

it('advertises the oauth authorization server metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('registration_endpoint', url('oauth/register'))
        ->assertJsonPath('scopes_supported', ['mcp:use'])
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

it('advertises the protected resource metadata', function () {
    $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
});

it('registers an oauth client dynamically for an allowed redirect domain', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])
        ->assertCreated()
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonStructure(['client_id', 'redirect_uris']);

    expect(DB::table('oauth_clients')->where('name', 'Claude')->exists())->toBeTrue();
});

it('rejects dynamic registration for a disallowed redirect domain', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Evil',
        'redirect_uris' => ['https://evil.example/callback'],
    ])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(DB::table('oauth_clients')->where('name', 'Evil')->exists())->toBeFalse();
});

it('serves MCP reads for an oauth-authenticated user', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'OAU']);
    Project::factory()->create(['short_name' => 'FRN']);

    $response = callMcpToolViaOAuth($user, 'list-projects-tool')->assertOk();

    expect($response->content())
        ->toContain('OAU')
        ->not->toContain('FRN');
});

it('allows MCP write tools for an oauth token', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'OAU']);

    callMcpToolViaOAuth($user, 'create-task-tool', [
        'reference' => 'OAU',
        'title' => 'Created via OAuth',
    ])->assertOk();

    expect(Task::where('title', 'Created via OAuth')->exists())->toBeTrue();
});

it('rejects unauthenticated MCP requests with a 401', function () {
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertUnauthorized();
});

it('renders the consent screen translated into the user locale', function () {
    $this->withoutVite();
    app()->setLocale('de');

    $html = view('mcp.authorize', [
        'client' => new Client(['name' => 'Claude']),
        'user' => User::factory()->create(),
        'scopes' => [new Scope('mcp:use', 'Use MCP server')],
        'authToken' => 'test-token',
        'projects' => Project::query()->get(),
        'grantRestricts' => false,
        'grantProjectIds' => [],
    ])->render();

    expect($html)
        ->toContain('Claude autorisieren')
        ->toContain('Angemeldet als:')
        ->toContain('MCP-Server nutzen');
});

it('blocks deactivated users authenticated via oauth', function () {
    $user = User::factory()->create();
    $user->deactivate();

    callMcpToolViaOAuth($user, 'list-projects-tool')->assertForbidden();
});
