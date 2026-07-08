<?php

use App\Livewire\Settings\ApiTokens;
use App\Models\McpClientGrant;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The authorization endpoints construct the OAuth server, which loads the
    // signing keys at instantiation; generate them into a per-process temp
    // directory so the tests do not depend on storage/ keys existing (CI).
    $keyDir = sys_get_temp_dir().'/kanbrio-passport-keys-'.getmypid();
    File::ensureDirectoryExists($keyDir);
    Passport::loadKeysFrom($keyDir);

    if (! file_exists($keyDir.'/oauth-private.key')) {
        Artisan::call('passport:keys');
    }

    $this->user = User::factory()->create();
    $this->allowed = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ALW', 'title' => 'Allowed']);
    $this->other = Project::factory()->withMembers([$this->user])->create(['short_name' => 'OTH', 'title' => 'Other membership']);

    $this->client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
        enableDeviceFlow: false,
    );
});

/**
 * Create a grant for the test client/user pair, restricted to the given
 * projects (unrestricted when none are given).
 *
 * @param  array<int, Project>  $projects
 */
function grantConnection(User $user, Client $client, array $projects = []): McpClientGrant
{
    $grant = McpClientGrant::query()->create([
        'oauth_client_id' => $client->getKey(),
        'user_id' => $user->id,
        'restricts_projects' => $projects !== [],
    ]);

    $grant->projects()->attach(collect($projects)->pluck('id'));

    return $grant;
}

/**
 * Call an MCP tool over the /mcp HTTP endpoint as the given user,
 * authenticated by an OAuth token of the test client.
 */
function callMcpToolViaOAuthClient(User $user, Client $client, string $tool, array $arguments = [])
{
    Passport::actingAs($user, ['mcp:use'], 'api', $client);

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

/**
 * Begin the authorization-code flow in the browser, landing on the consent
 * screen with the authorization request stored in the session.
 */
function startAuthorization(User $user, Client $client)
{
    return test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-123',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'a-test-verifier-of-sufficient-length-1234567890', true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]));
}

it('shows the project picker on the consent screen', function () {
    $response = startAuthorization($this->user, $this->client);

    $response->assertOk();

    expect($response->content())
        ->toContain('data-test="oauth-scope-all"')
        ->toContain('data-test="oauth-project-ALW"')
        ->toContain('data-test="oauth-project-OTH"');
});

it('persists a restricted grant when approving with selected projects', function () {
    startAuthorization($this->user, $this->client)->assertOk();

    $response = $this->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'project_scope' => 'selected',
        'projects' => [$this->allowed->id],
    ]);

    expect($response->headers->get('Location'))->toContain('code=');

    $grant = McpClientGrant::query()
        ->where('oauth_client_id', $this->client->getKey())
        ->where('user_id', $this->user->id)
        ->firstOrFail();

    expect($grant->restrictsProjects())->toBeTrue();
    expect($grant->projects()->pluck('projects.id')->all())->toBe([$this->allowed->id]);
});

it('persists an unrestricted grant when approving with all projects', function () {
    startAuthorization($this->user, $this->client)->assertOk();

    $this->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'project_scope' => 'all',
    ]);

    $grant = McpClientGrant::query()->firstOrFail();

    expect($grant->restrictsProjects())->toBeFalse();
    expect($grant->projects()->count())->toBe(0);
});

it('rejects approving with a project the user is not a member of', function () {
    $foreign = Project::factory()->create();

    startAuthorization($this->user, $this->client)->assertOk();

    $this->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'project_scope' => 'selected',
        'projects' => [$foreign->id],
    ])->assertSessionHasErrors('projects');

    expect(McpClientGrant::query()->count())->toBe(0);
});

it('pre-selects the existing grant on re-consent', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    $response = startAuthorization($this->user, $this->client);

    $response->assertOk();

    expect($response->content())
        ->toMatch('/name="project_scope" value="selected"[^>]*checked/')
        ->toMatch('/value="'.$this->allowed->id.'"[^>]*checked/');
});

it('updates the existing grant when re-approving with a different scope', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    startAuthorization($this->user, $this->client)->assertOk();

    $this->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'project_scope' => 'all',
    ]);

    $grant = McpClientGrant::query()->firstOrFail();

    expect(McpClientGrant::query()->count())->toBe(1);
    expect($grant->restrictsProjects())->toBeFalse();
    expect($grant->projects()->count())->toBe(0);
});

it('limits MCP reads to the grant projects', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    $response = callMcpToolViaOAuthClient($this->user, $this->client, 'list-projects-tool')->assertOk();

    expect($response->content())
        ->toContain('ALW')
        ->not->toContain('OTH');
});

it('denies MCP access to tasks of out-of-scope projects', function () {
    $task = Task::factory()->for($this->other)->create(['title' => 'Hidden task']);
    grantConnection($this->user, $this->client, [$this->allowed]);

    $response = callMcpToolViaOAuthClient($this->user, $this->client, 'get-task-tool', [
        'reference' => $task->reference,
    ])->assertOk();

    expect($response->content())->not->toContain('Hidden task');
});

it('denies MCP writes to out-of-scope projects', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    callMcpToolViaOAuthClient($this->user, $this->client, 'create-task-tool', [
        'reference' => 'OTH',
        'title' => 'Sneaky task',
    ]);

    expect(Task::where('title', 'Sneaky task')->exists())->toBeFalse();
});

it('allows MCP writes within the grant projects', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    callMcpToolViaOAuthClient($this->user, $this->client, 'create-task-tool', [
        'reference' => 'ALW',
        'title' => 'In-scope task',
    ])->assertOk();

    expect($this->allowed->tasks()->where('title', 'In-scope task')->exists())->toBeTrue();
});

it('denies project creation to a restricted connection', function () {
    $user = User::factory()->canCreateProjects()->create();
    $project = Project::factory()->withMembers([$user])->create();
    grantConnection($user, $this->client, [$project]);

    callMcpToolViaOAuthClient($user, $this->client, 'create-project-tool', [
        'title' => 'New project',
        'short_name' => 'NEW',
    ]);

    expect(Project::where('short_name', 'NEW')->exists())->toBeFalse();
});

it('keeps an unrestricted connection at full access', function () {
    grantConnection($this->user, $this->client);

    $response = callMcpToolViaOAuthClient($this->user, $this->client, 'list-projects-tool')->assertOk();

    expect($response->content())
        ->toContain('ALW')
        ->toContain('OTH');
});

it('does not fall back to all projects when the only allowed project is deleted', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    $this->allowed->delete();

    $response = callMcpToolViaOAuthClient($this->user, $this->client, 'list-projects-tool')->assertOk();

    expect($response->content())->not->toContain('OTH');
});

it('does not restrict the owner web session', function () {
    grantConnection($this->user, $this->client, [$this->allowed]);

    $this->actingAs($this->user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Allowed')
        ->assertSee('Other membership');
});

it('lists connections with their project scope in the settings component', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    joinProject($this->allowed, $user);
    $grant = grantConnection($user, $this->client, [$this->allowed]);

    $connections = collect(
        Livewire\Livewire::actingAs($user)
            ->test(ApiTokens::class)
            ->instance()
            ->connections()
    )->keyBy('id');

    expect($connections[$grant->id]['client_name'])->toBe('Claude');
    expect($connections[$grant->id]['projects_label'])->toBe('ALW');
});

it('revokes a connection: tokens revoked, grant deleted', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    joinProject($this->allowed, $user);
    $grant = grantConnection($user, $this->client, [$this->allowed]);

    Token::query()->forceCreate([
        'id' => 'token-1',
        'user_id' => $user->id,
        'client_id' => $this->client->getKey(),
        'scopes' => ['mcp:use'],
        'revoked' => false,
    ]);
    RefreshToken::query()->forceCreate([
        'id' => 'refresh-1',
        'access_token_id' => 'token-1',
        'revoked' => false,
    ]);

    Livewire\Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->call('revokeConnection', $grant->id);

    expect(McpClientGrant::query()->whereKey($grant->id)->exists())->toBeFalse();
    expect(Token::query()->findOrFail('token-1')->revoked)->toBeTrue();
    expect(RefreshToken::query()->findOrFail('refresh-1')->revoked)->toBeTrue();
});

it('does not revoke another user\'s connection', function () {
    $grant = grantConnection($this->user, $this->client, [$this->allowed]);
    $stranger = User::factory()->canCreateApiTokens()->create();

    Livewire\Livewire::actingAs($stranger)
        ->test(ApiTokens::class)
        ->call('revokeConnection', $grant->id);

    expect(McpClientGrant::query()->whereKey($grant->id)->exists())->toBeTrue();
});

it('serves the task tools end-to-end with a really issued oauth token', function () {
    // Full flow — authorize, approve, exchange the code for a JWT — so the MCP
    // call runs through Passport's real guard instead of Passport::actingAs().
    startAuthorization($this->user, $this->client)->assertOk();

    $redirect = $this->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'project_scope' => 'all',
    ])->headers->get('Location');

    parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

    $accessToken = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'code_verifier' => 'a-test-verifier-of-sufficient-length-1234567890',
        'code' => $query['code'],
    ])->assertOk()->json('access_token');

    // Drop the browser-flow session and guards, so the MCP call below cannot
    // piggyback on the web login and must authenticate via the JWT.
    $this->flushSession();
    auth()->forgetGuards();

    $create = $this->withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'create-task-tool', 'arguments' => ['reference' => 'ALW', 'title' => 'Via real JWT']],
    ]);

    $create->assertOk();
    expect($create->json('result.isError') ?? false)->toBeFalse();

    $get = $this->withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'get-task-tool', 'arguments' => ['reference' => 'ALW-1']],
    ]);

    $get->assertOk();
    expect($get->content())->toContain('Via real JWT');
});

it('rejects OAuth tokens on the REST API, which stays Sanctum-only', function () {
    grantConnection($this->user, $this->client);

    Passport::actingAs($this->user, ['mcp:use'], 'api', $this->client);

    $this->getJson('/api/v1/projects')->assertUnauthorized();
});
