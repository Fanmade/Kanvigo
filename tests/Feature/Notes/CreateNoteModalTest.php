<?php

use App\Livewire\Notes\CreateNoteModal;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates a private, projectless note', function () {
    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open')
        ->set('title', 'Idea')
        ->set('body', '<p>Capture this</p>')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false);

    $note = Note::sole();
    expect($note->user_id)->toBe($this->user->id)
        ->and($note->title)->toBe('Idea')
        ->and($note->project_id)->toBeNull()
        ->and($note->is_public)->toBeFalse();
});

it('requires a title', function () {
    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);

    expect(Note::count())->toBe(0);
});

it('attaches a note to a project and makes it public', function () {
    $project = Project::factory()->create();
    joinProject($project, $this->user);

    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open')
        ->set('title', 'Shared')
        ->set('projectId', $project->id)
        ->set('isPublic', true)
        ->call('save')
        ->assertHasNoErrors();

    $note = Note::sole();
    expect($note->project_id)->toBe($project->id)
        ->and($note->is_public)->toBeTrue();
});

it('resets the public toggle when the project is cleared', function () {
    $project = Project::factory()->create();
    joinProject($project, $this->user);

    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open')
        ->set('projectId', $project->id)
        ->set('isPublic', true)
        ->set('projectId', null)
        ->assertSet('isPublic', false);
});

it('never persists public without a project', function () {
    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open')
        ->set('title', 'Still private')
        ->set('isPublic', true) // no project chosen
        ->call('save');

    expect(Note::sole()->is_public)->toBeFalse();
});

it('edits an existing note without creating a second one', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Old title']);

    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open', $note->id)
        ->assertSet('title', 'Old title')
        ->set('title', 'New title')
        ->call('save')
        ->assertHasNoErrors();

    expect($note->fresh()->title)->toBe('New title')
        ->and(Note::count())->toBe(1);
});

it('forbids opening another user\'s note for editing', function () {
    $note = Note::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateNoteModal::class)
        ->call('open', $note->id)
        ->assertForbidden();
});
