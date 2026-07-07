<?php

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * Create a personal access token restricted to the given projects and return
 * its plain-text value for Bearer authentication.
 *
 * @param  array<int, Project>  $projects
 * @param  array<int, string>  $abilities
 */
function createProjectRestrictedToken(User $user, array $projects, array $abilities = ['read', 'write']): string
{
    $newToken = $user->createToken('Scoped agent', $abilities);

    $newToken->accessToken->forceFill(['restricts_projects' => true])->save();
    $newToken->accessToken->projects()->attach(collect($projects)->pluck('id'));

    return $newToken->plainTextToken;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->allowed = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ALW', 'title' => 'Allowed']);
    $this->other = Project::factory()->withMembers([$this->user])->create(['short_name' => 'OTH', 'title' => 'Other membership']);
});

it('lists only the projects a restricted token allows', function () {
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    withToken($token)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.short_name', 'ALW');
});

it('404s an out-of-scope project the user is a member of, without leaking it', function () {
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    withToken($token)->getJson('/api/v1/projects/OTH')->assertNotFound();
});

it('404s tasks of an out-of-scope project', function () {
    $task = Task::factory()->for($this->other)->create();
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    withToken($token)->getJson("/api/v1/tasks/{$task->reference}")->assertNotFound();
});

it('reads and writes normally within the allowed projects', function () {
    Task::factory()->for($this->allowed)->create(['title' => 'Visible task']);
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    withToken($token)->getJson('/api/v1/projects/ALW')
        ->assertOk()
        ->assertJsonPath('data.short_name', 'ALW');

    withToken($token)->postJson('/api/v1/projects/ALW/tasks', ['title' => 'Created in scope'])
        ->assertCreated();

    expect($this->allowed->tasks()->where('title', 'Created in scope')->exists())->toBeTrue();
});

it('denies write attempts on an out-of-scope project with a 404', function () {
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    withToken($token)->postJson('/api/v1/projects/OTH/tasks', ['title' => 'Sneaky'])
        ->assertNotFound();

    expect($this->other->tasks()->count())->toBe(0);
});

it('denies project creation to a restricted token even when the user may create projects', function () {
    $user = User::factory()->canCreateProjects()->create();
    $project = Project::factory()->withMembers([$user])->create();
    $token = createProjectRestrictedToken($user, [$project]);

    withToken($token)->postJson('/api/v1/projects', ['title' => 'New', 'short_name' => 'NEW'])
        ->assertForbidden();

    expect(Project::where('short_name', 'NEW')->exists())->toBeFalse();
});

it('does not widen a restricted token through the access-all-projects grant', function () {
    $user = User::factory()->withPermission(Permission::AccessAllProjects)->create();
    $mine = Project::factory()->withMembers([$user])->create(['short_name' => 'MIN']);
    Project::factory()->create(['short_name' => 'FAR']);
    $token = createProjectRestrictedToken($user, [$mine]);

    withToken($token)->getJson('/api/v1/projects/FAR')->assertNotFound();
});

it('keeps an unrestricted token unaffected', function () {
    $token = $this->user->createToken('Legacy', ['read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('does not fall back to all projects when the only allowed project is deleted', function () {
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    $this->allowed->delete();

    withToken($token)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    withToken($token)->getJson('/api/v1/projects/OTH')->assertNotFound();
});

it('restricts MCP tools to the token projects too', function () {
    $token = createProjectRestrictedToken($this->user, [$this->allowed]);

    $response = withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list-projects-tool', 'arguments' => []],
    ])->assertOk();

    expect($response->content())
        ->toContain('ALW')
        ->not->toContain('OTH');
});

it('does not restrict the owner web session when they hold a restricted token', function () {
    createProjectRestrictedToken($this->user, [$this->allowed]);

    $this->actingAs($this->user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Allowed')
        ->assertSee('Other membership');
});
