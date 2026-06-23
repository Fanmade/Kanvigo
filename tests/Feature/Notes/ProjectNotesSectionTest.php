<?php

use App\Livewire\Projects\ProjectShow;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->owner->id, $this->member->id]);

    $this->view = fn (User $user) => Livewire::actingAs($user)
        ->test(ProjectShow::class, ['short_name' => 'ABC']);
});

it('lists only the project\'s public notes', function () {
    $public = Note::factory()->for($this->owner)->publicTo($this->project)->create();
    Note::factory()->for($this->owner)->attachedTo($this->project)->create();              // private attached
    Note::factory()->for($this->owner)->publicTo(Project::factory()->create())->create();  // other project

    $ids = ($this->view)($this->member)->instance()->publicNotes()->pluck('id')->all();

    expect($ids)->toBe([$public->id]);
});

it('shows a public note to members but hides private attached notes', function () {
    Note::factory()->for($this->owner)->publicTo($this->project)->create(['title' => 'Shared idea']);
    Note::factory()->for($this->owner)->attachedTo($this->project)->create(['title' => 'Secret idea']);

    ($this->view)($this->member)
        ->assertSee('Shared idea')
        ->assertDontSee('Secret idea');
});

it('forbids a non-owner from deleting a public note', function () {
    $note = Note::factory()->for($this->owner)->publicTo($this->project)->create();

    expect(fn () => ($this->view)($this->member)->call('deleteNote', $note->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Note::find($note->id))->not->toBeNull();
});

it('forbids a non-owner from changing a public note\'s visibility', function () {
    $note = Note::factory()->for($this->owner)->publicTo($this->project)->create();

    expect(fn () => ($this->view)($this->member)->call('toggleNoteVisibility', $note->id))
        ->toThrow(ModelNotFoundException::class);

    expect($note->fresh()->is_public)->toBeTrue();
});

it('lets the owner unshare their note from the project page', function () {
    $note = Note::factory()->for($this->owner)->publicTo($this->project)->create();

    ($this->view)($this->owner)->call('toggleNoteVisibility', $note->id);

    expect($note->fresh()->is_public)->toBeFalse();
});
