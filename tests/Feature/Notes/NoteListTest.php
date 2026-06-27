<?php

use App\Livewire\Notes\NoteList;
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

it('requires authentication to reach the notes page', function () {
    $this->get(route('notes.index'))->assertRedirect(route('login'));
});

it('renders the notes page for an authenticated user', function () {
    $this->actingAs($this->user)->get(route('notes.index'))->assertOk();
});

it('lists the user\'s own notes, hiding others and empty drafts', function () {
    $mine = Note::factory()->for($this->user)->create(['title' => 'Grocery list']);
    Note::factory()->for($this->user)->create(['title' => '']);   // abandoned draft
    Note::factory()->create(['title' => 'Someone else note']);    // another user's

    $component = Livewire::actingAs($this->user)->test(NoteList::class);

    expect($component->instance()->notes()->pluck('id'))
        ->toContain($mine->id)
        ->toHaveCount(1);

    $component->assertSee('Grocery list')->assertDontSee('Someone else note');
});

it('places a newly created note at the top by default', function () {
    $older = Note::factory()->for($this->user)->create(['title' => 'Older']);
    $newer = Note::factory()->for($this->user)->create(['title' => 'Newer']);

    expect($newer->position)->toBeGreaterThan($older->position);

    $ids = Livewire::actingAs($this->user)->test(NoteList::class)->instance()->notes()->pluck('id');

    expect($ids->first())->toBe($newer->id)
        ->and($ids->last())->toBe($older->id);
});

it('moves a note down and back up within the list', function () {
    $a = Note::factory()->for($this->user)->create(['title' => 'A']);
    $b = Note::factory()->for($this->user)->create(['title' => 'B']);
    $c = Note::factory()->for($this->user)->create(['title' => 'C']);

    // Newest-first default: C, B, A.
    $component = Livewire::actingAs($this->user)->test(NoteList::class);
    expect($component->instance()->notes()->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);

    // Move C down one slot: B, C, A.
    $component->call('moveNoteDown', $c->id);
    expect($component->instance()->notes()->pluck('id')->all())->toBe([$b->id, $c->id, $a->id]);

    // Move C back up: C, B, A.
    $component->call('moveNoteUp', $c->id);
    expect($component->instance()->notes()->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

it('keeps reordering within the pin group — an unpinned note cannot jump above a pinned one', function () {
    $pinned = Note::factory()->for($this->user)->pinned()->create(['title' => 'Pinned']);
    $top = Note::factory()->for($this->user)->create(['title' => 'Top unpinned']);

    // Order: Pinned, Top unpinned. Moving the unpinned note up is a no-op (it
    // would cross into the pinned group).
    $component = Livewire::actingAs($this->user)->test(NoteList::class)->call('moveNoteUp', $top->id);

    expect($component->instance()->notes()->pluck('id')->all())->toBe([$pinned->id, $top->id]);
});

it('cannot reorder a note owned by someone else', function () {
    $foreign = Note::factory()->create(['title' => 'Not yours']);

    expect(fn () => Livewire::actingAs($this->user)->test(NoteList::class)->call('moveNoteUp', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

it('deletes one of the user\'s own notes from the page', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Throwaway']);

    Livewire::actingAs($this->user)
        ->test(NoteList::class)
        ->call('deleteNote', $note->id)
        ->assertHasNoErrors();

    expect(Note::query()->whereKey($note->id)->exists())->toBeFalse();
});

it('pins and unpins one of the user\'s own notes', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Keep me handy']);

    $component = Livewire::actingAs($this->user)->test(NoteList::class)->call('togglePin', $note->id);
    expect($note->refresh()->is_pinned)->toBeTrue();

    $component->call('togglePin', $note->id);
    expect($note->refresh()->is_pinned)->toBeFalse();
});

it('cannot pin a note owned by someone else', function () {
    $foreign = Note::factory()->create(['title' => 'Not yours']);

    expect(fn () => Livewire::actingAs($this->user)->test(NoteList::class)->call('togglePin', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->refresh()->is_pinned)->toBeFalse();
});

it('sorts pinned notes above unpinned ones regardless of recency', function () {
    // The pinned note is older, yet must still come first.
    $pinned = Note::factory()->for($this->user)->pinned()->create(['title' => 'Pinned', 'updated_at' => now()->subWeek()]);
    $recent = Note::factory()->for($this->user)->create(['title' => 'Recent', 'updated_at' => now()]);

    $ids = Livewire::actingAs($this->user)->test(NoteList::class)->instance()->notes()->pluck('id');

    expect($ids->first())->toBe($pinned->id)
        ->and($ids->last())->toBe($recent->id);
});

it('searches notes by title and body, case-insensitively', function () {
    $byTitle = Note::factory()->for($this->user)->create(['title' => 'Deployment checklist', 'body' => '<p>nothing here</p>']);
    $byBody = Note::factory()->for($this->user)->create(['title' => 'Random', 'body' => '<p>remember to deploy on Friday</p>']);
    Note::factory()->for($this->user)->create(['title' => 'Groceries', 'body' => '<p>milk</p>']);

    $ids = Livewire::actingAs($this->user)->test(NoteList::class)
        ->set('search', 'DEPLOY')
        ->instance()->notes()->pluck('id');

    expect($ids)->toContain($byTitle->id)
        ->toContain($byBody->id)
        ->toHaveCount(2);
});

it('filters notes by project', function () {
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $inProject = Note::factory()->for($this->user)->create(['title' => 'Attached', 'project_id' => $project->id]);
    Note::factory()->for($this->user)->create(['title' => 'Loose note']);

    $ids = Livewire::actingAs($this->user)->test(NoteList::class)
        ->set('projectFilter', (string) $project->id)
        ->instance()->notes()->pluck('id');

    expect($ids)->toEqual(collect([$inProject->id]));
});

it('filters to notes without a project', function () {
    $project = Project::factory()->create();
    Note::factory()->for($this->user)->create(['title' => 'Attached', 'project_id' => $project->id]);
    $loose = Note::factory()->for($this->user)->create(['title' => 'Loose note']);

    $ids = Livewire::actingAs($this->user)->test(NoteList::class)
        ->set('projectFilter', 'none')
        ->instance()->notes()->pluck('id');

    expect($ids)->toEqual(collect([$loose->id]));
});

it('hides the reorder controls while filtering', function () {
    $note = Note::factory()->for($this->user)->create(['title' => 'Alpha']);

    $component = Livewire::actingAs($this->user)->test(NoteList::class);
    $component->assertSeeHtml('data-test="move-note-up-'.$note->id.'"');

    // The note still matches the search, but reordering is off while filtering.
    $component->set('search', 'Alpha')
        ->assertSee('Alpha')
        ->assertDontSeeHtml('data-test="move-note-up-'.$note->id.'"');
});

it('shows a filter-aware empty state when nothing matches', function () {
    Note::factory()->for($this->user)->create(['title' => 'Alpha']);

    Livewire::actingAs($this->user)->test(NoteList::class)
        ->set('search', 'no-such-note')
        ->assertSee('No notes match your search.');
});

it('refreshes the list when a note is saved elsewhere', function () {
    $component = Livewire::actingAs($this->user)->test(NoteList::class);

    expect($component->instance()->notes())->toHaveCount(0);

    Note::factory()->for($this->user)->create(['title' => 'Fresh idea']);

    $component->dispatch('note-saved')->assertSee('Fresh idea');
});
