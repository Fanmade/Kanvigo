<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\ConvertNoteTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\GetNoteTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

// create-note

it('creates a private projectless note', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'My note', 'body' => '<p>Hi</p>'])
        ->assertOk()
        ->assertSee('My note');

    assertDatabaseHas('notes', ['user_id' => $user->id, 'title' => 'My note', 'project_id' => null, 'is_public' => false]);
});

it('attaches a note to a project and makes it public', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'Shared', 'project' => 'ABC', 'public' => true])->assertOk();

    assertDatabaseHas('notes', ['title' => 'Shared', 'project_id' => $project->id, 'is_public' => true]);
});

it('decodes an HTML-escaped ampersand in the note title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'Ben &amp; Jerry'])->assertOk();

    assertDatabaseHas('notes', ['user_id' => $user->id, 'title' => 'Ben & Jerry']);
});

it('keeps a note private when made public without a project', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'No project', 'public' => true])->assertOk();

    assertDatabaseHas('notes', ['title' => 'No project', 'is_public' => false]);
});

it('errors creating a note in an inaccessible project', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    Project::factory()->create(['short_name' => 'ZZZ']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'x', 'project' => 'ZZZ'])->assertHasErrors();
});

it('denies creating a note with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);

    KanvigoServer::tool(CreateNoteTool::class, ['title' => 'x'])->assertHasErrors();
});

// list-notes

it('lists own notes plus public notes in the user\'s projects', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->withMembers([$user, $other])->create(['short_name' => 'ABC']);
    $foreign = Project::factory()->withMembers([$other])->create(['short_name' => 'XYZ']);
    Sanctum::actingAs($user, ['read']);

    Note::factory()->for($user)->create(['title' => 'Mine']);
    Note::factory()->for($other)->publicTo($project)->create(['title' => 'Shared here']);
    Note::factory()->for($other)->attachedTo($project)->create(['title' => 'Hidden private']);
    Note::factory()->for($other)->publicTo($foreign)->create(['title' => 'Elsewhere']);

    KanvigoServer::tool(ListNotesTool::class, [])
        ->assertOk()
        ->assertSee('Mine')
        ->assertSee('Shared here')
        ->assertDontSee('Hidden private')
        ->assertDontSee('Elsewhere');
});

// get-note

it('gets an owned note by numeric id, including the body', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $note = Note::factory()->for($user)->create(['title' => 'Readable', 'body' => '<p>Body text</p>']);

    KanvigoServer::tool(GetNoteTool::class, ['id' => $note->id])
        ->assertOk()
        ->assertSee('Readable')
        ->assertSee('Body text');
});

it('errors getting another user\'s private note', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $note = Note::factory()->create();

    KanvigoServer::tool(GetNoteTool::class, ['id' => $note->id])->assertHasErrors();
});

// update-note

it('updates an owned note\'s title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $note = Note::factory()->for($user)->create(['title' => 'Old']);

    KanvigoServer::tool(UpdateNoteTool::class, ['id' => $note->id, 'title' => 'New'])->assertOk();

    expect($note->fresh()->title)->toBe('New');
});

it('detaches a note and forces it private', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $note = Note::factory()->for($user)->publicTo($project)->create();

    KanvigoServer::tool(UpdateNoteTool::class, ['id' => $note->id, 'project' => ''])->assertOk();

    expect($note->fresh()->project_id)->toBeNull()
        ->and($note->fresh()->is_public)->toBeFalse();
});

it('errors updating another user\'s note', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $note = Note::factory()->create();

    KanvigoServer::tool(UpdateNoteTool::class, ['id' => $note->id, 'title' => 'Hax'])->assertHasErrors();
});

// convert-note

it('converts a note into a task and links them', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $note = Note::factory()->for($user)->create(['title' => 'Convert me']);

    KanvigoServer::tool(ConvertNoteTool::class, ['id' => $note->id, 'reference' => 'ABC'])
        ->assertOk()
        ->assertSee('ABC-');

    $task = $project->tasks()->sole();

    expect($note->fresh()->converted_task_id)->toBe($task->id)
        ->and($task->title)->toBe('Convert me');
});

it('errors converting another user\'s note', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $note = Note::factory()->create();

    KanvigoServer::tool(ConvertNoteTool::class, ['id' => $note->id, 'reference' => 'ABC'])->assertHasErrors();
});
