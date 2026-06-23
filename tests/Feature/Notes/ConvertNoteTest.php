<?php

use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
});

it('prefills the task dialog from the note', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Idea', 'body' => '<p>Details</p>']);

    Livewire::actingAs($this->user)
        ->test(CreateTaskModal::class)
        ->call('open', null, null, $note->id)
        ->assertSet('title', 'Idea')
        ->assertSet('description', '<p>Details</p>')
        ->assertSet('fromNoteId', $note->id)
        ->assertSet('show', true);
});

it('converts a note into a task, links them back, and keeps the note', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Convert me']);

    Livewire::actingAs($this->user)
        ->test(CreateTaskModal::class)
        ->call('open', null, null, $note->id)
        ->set('projectId', $this->project->id)
        ->call('save')
        ->assertHasNoErrors();

    $task = $this->project->tasks()->sole();

    expect($task->title)->toBe('Convert me')
        ->and($note->fresh()->converted_task_id)->toBe($task->id);
});

it('defaults the task project to the note\'s attached project', function () {
    $note = Note::factory()->for($this->user)->attachedTo($this->project)->create();

    Livewire::actingAs($this->user)
        ->test(CreateTaskModal::class)
        ->call('open', null, null, $note->id)
        ->assertSet('projectId', $this->project->id);
});

it('forbids converting another user\'s note', function () {
    $note = Note::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateTaskModal::class)
        ->call('open', null, null, $note->id)
        ->assertForbidden();
});
