<?php

use App\Livewire\Projects\ProjectList;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('suggests a short name from the title when none is set', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->assertSet('short_name', 'MCP');
});

it('does not overwrite a short name the user already entered', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->test(ProjectList::class)
        ->set('short_name', 'XYZ')
        ->set('title', 'My Cool Project')
        ->assertSet('short_name', 'XYZ');
});

it('lists only the projects the user belongs to, ordered by title', function () {
    $user = User::factory()->create();

    $beta = Project::factory()->create(['title' => 'Beta']);
    $alpha = Project::factory()->create(['title' => 'Alpha']);
    joinProject($beta, $user);
    joinProject($alpha, $user);

    Project::factory()->create(['title' => 'Not Mine']);

    $projects = Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->instance()
        ->projects();

    expect($projects->pluck('title')->all())->toBe(['Alpha', 'Beta']);
});

it('renders the project description as sanitized rich text, clamped, on the card', function () {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'title' => 'Documented',
        'description' => '<p>Hello <strong>world</strong></p>',
    ]);
    joinProject($project, $user);

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->assertSeeHtml('<strong>world</strong>') // rendered, not escaped raw markup
        ->assertSeeHtml('line-clamp-3');           // capped to a few lines
});

it('opens the create form when deep-linked with the create flag', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->withQueryParams(['create' => true])
        ->test(ProjectList::class)
        ->assertSet('showCreate', true);
});

it('keeps the create form closed without the flag', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->test(ProjectList::class)
        ->assertSet('showCreate', false);
});

it('does not render the create form for users who cannot create projects', function () {
    Livewire::actingAs(User::factory()->create())
        ->withQueryParams(['create' => true])
        ->test(ProjectList::class)
        ->assertDontSee('Short name');
});

it('creates a project, attaches the creator and redirects to it', function () {
    $user = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->set('short_name', 'mcp')
        ->set('description', 'A project for testing.')
        ->call('createProject')
        ->assertHasNoErrors()
        ->assertRedirect(route('project.show', ['short_name' => 'MCP']));

    $project = Project::where('short_name', 'MCP')->first();

    expect($project)->not->toBeNull()
        ->and($project->title)->toBe('My Cool Project')
        ->and($project->members()->whereKey($user->id)->exists())->toBeTrue()
        // The creator owns the project they just made.
        ->and($project->isOwner($user))->toBeTrue();
});

it('forbids creating a project without the create-projects permission', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->set('short_name', 'MCP')
        ->call('createProject')
        ->assertForbidden();

    expect(Project::where('title', 'My Cool Project')->exists())->toBeFalse();
});

it('rejects a duplicate or reserved short name', function (string $shortName) {
    Project::factory()->create(['short_name' => 'DUP']);

    $user = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->set('short_name', $shortName)
        ->call('createProject')
        ->assertHasErrors('short_name');

    expect(Project::where('title', 'My Cool Project')->exists())->toBeFalse();
})->with(['DUP', 'API', 'A']);
