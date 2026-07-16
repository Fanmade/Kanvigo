<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\UpdateProjectTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * A project whose settings the given user is allowed to manage (owner role),
 * acting with a read/write token.
 */
function projectManagedBy(User $user, string $shortName = 'ABC'): Project
{
    $project = Project::factory()->create(['short_name' => $shortName, 'title' => 'Old title', 'description' => '<p>Old</p>']);
    joinProject($project, $user, 'owner');

    return $project;
}

it('updates a project title and description with a write token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'New title',
        'description' => '<p>New description</p>',
    ])
        ->assertOk()
        ->assertSee('New title');

    $project->refresh();
    expect($project->title)->toBe('New title')
        ->and($project->description)->toBe('<p>New description</p>');
});

it('updates only the provided field, leaving the other untouched', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Just the title',
    ])->assertOk();

    $project->refresh();
    expect($project->title)->toBe('Just the title')
        ->and($project->description)->toBe('<p>Old</p>');
});

it('records the change in the audit trail', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Audited title',
    ])->assertOk();

    // Title/description edits are audited to the outbox (they are not
    // feed-worthy actions), exactly like task field edits.
    $titleChanged = DB::table('audit_outbox')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->event, true))
        ->first(fn (array $event): bool => $event['action'] === 'title_changed'
            && $event['subject_type'] === $project->getMorphClass()
            && $event['subject_id'] === $project->id);

    expect($titleChanged)->not->toBeNull()
        ->and($titleChanged['actor_id'])->toBe($user->id)
        ->and($titleChanged['metadata']['new'])->toBe('Audited title');
});

it('decodes an HTML-escaped ampersand in the project title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Research &amp; Development',
    ])->assertOk();

    assertDatabaseHas('projects', ['short_name' => 'ABC', 'title' => 'Research & Development']);
});

it('sanitizes disallowed HTML in the description', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'description' => '<p>Safe</p><script>alert(1)</script>',
    ])->assertOk();

    expect($project->refresh()->description)->not->toContain('<script>');
});

it('errors when neither title nor description is provided', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, ['short_name' => 'ABC'])->assertHasErrors();
});

it('rejects an empty title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, ['short_name' => 'ABC', 'title' => '   '])->assertHasErrors();
});

it('denies a project member who cannot manage settings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Sanctum::actingAs($member, ['read', 'write']);
    $project = projectManagedBy($owner);
    joinProject($project, $member, 'member');

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Nope',
    ])->assertHasErrors();

    expect($project->refresh()->title)->toBe('Old title');
});

it('denies a non-member without leaking the project', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider, ['read', 'write']);
    projectManagedBy($owner);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Nope',
    ])->assertHasErrors();
});

it('denies an update with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    projectManagedBy($user);

    KanvigoServer::tool(UpdateProjectTool::class, [
        'short_name' => 'ABC',
        'title' => 'Nope',
    ])->assertHasErrors();
});
