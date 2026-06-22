<?php

use App\Livewire\CommandPalette;
use App\Livewire\Dashboard;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('offers a New note action in the command palette', function () {
    $action = Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->instance()
        ->actions()
        ->firstWhere('title', 'New note');

    expect($action)->not->toBeNull()
        ->and($action->event)->toBe('open-create-note');
});

it('lists the user\'s notes, hiding others and empty drafts', function () {
    $mine = Note::factory()->for($this->user)->create(['title' => 'Mine']);
    Note::factory()->for($this->user)->create(['title' => '']);      // abandoned draft
    Note::factory()->create(['title' => 'Theirs']);                  // another user's

    $ids = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->notes()->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->toHaveCount(1);
});

it('deletes the user\'s own note', function () {
    $note = Note::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->call('deleteNote', $note->id);

    expect(Note::find($note->id))->toBeNull(); // soft-deleted, out of the default scope
});

it('cannot delete another user\'s note', function () {
    $note = Note::factory()->create();

    expect(fn () => Livewire::actingAs($this->user)->test(Dashboard::class)->call('deleteNote', $note->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Note::find($note->id))->not->toBeNull();
});

it('toggles the visibility of an attached note', function () {
    $project = Project::factory()->create();
    $project->members()->attach($this->user);
    $note = Note::factory()->for($this->user)->attachedTo($project)->create();

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->call('toggleNoteVisibility', $note->id);

    expect($note->fresh()->is_public)->toBeTrue();
});

it('cannot make a projectless note public via the toggle', function () {
    $note = Note::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->call('toggleNoteVisibility', $note->id);

    expect($note->fresh()->is_public)->toBeFalse();
});

it('shows a saved note in the panel after the note-saved event', function () {
    $component = Livewire::actingAs($this->user)->test(Dashboard::class);

    Note::factory()->for($this->user)->create(['title' => 'Captured idea']);

    $component->dispatch('note-saved')
        ->assertSee('Captured idea');
});
