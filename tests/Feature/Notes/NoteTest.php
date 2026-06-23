<?php

use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires a title', function () {
    expect(fn () => Note::factory()->create(['title' => null]))
        ->toThrow(QueryException::class);
});

it('is private and projectless by default', function () {
    $note = Note::factory()->create();

    expect($note->project_id)->toBeNull()
        ->and($note->is_public)->toBeFalse();
});

describe('the public-requires-a-project invariant', function () {
    it('forces a note private when it has no project', function () {
        $note = Note::factory()->create(['project_id' => null, 'is_public' => true]);

        expect($note->is_public)->toBeFalse();
    });

    it('resets visibility to private when the project is cleared', function () {
        $note = Note::factory()->publicTo(Project::factory()->create())->create();
        expect($note->is_public)->toBeTrue();

        $note->update(['project_id' => null]);

        expect($note->fresh()->is_public)->toBeFalse();
    });

    it('keeps a note public while it stays attached', function () {
        $note = Note::factory()->publicTo(Project::factory()->create())->create();

        $note->update(['title' => 'Edited']);

        expect($note->fresh()->is_public)->toBeTrue();
    });
});

describe('the view policy', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->project = Project::factory()->create();
        joinProject($this->project, [$this->owner->id, $this->member->id]);
    });

    it('lets the owner view their own private note', function () {
        $note = Note::factory()->for($this->owner)->create();

        expect($this->owner->can('view', $note))->toBeTrue()
            ->and($this->member->can('view', $note))->toBeFalse();
    });

    it('lets project members view a public note', function () {
        $note = Note::factory()->for($this->owner)->publicTo($this->project)->create();

        expect($this->owner->can('view', $note))->toBeTrue()
            ->and($this->member->can('view', $note))->toBeTrue()
            ->and($this->stranger->can('view', $note))->toBeFalse();
    });

    it('hides an attached-but-private note from project members', function () {
        $note = Note::factory()->for($this->owner)->attachedTo($this->project)->create();

        expect($this->owner->can('view', $note))->toBeTrue()
            ->and($this->member->can('view', $note))->toBeFalse();
    });
});

describe('the write policies', function () {
    it('lets any authenticated user create a note', function () {
        expect(User::factory()->create()->can('create', Note::class))->toBeTrue();
    });

    it('restricts update, delete and visibility changes to the owner', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $note = Note::factory()->for($owner)->create();

        expect($owner->can('update', $note))->toBeTrue()
            ->and($owner->can('delete', $note))->toBeTrue()
            ->and($owner->can('changeVisibility', $note))->toBeTrue()
            ->and($other->can('update', $note))->toBeFalse()
            ->and($other->can('delete', $note))->toBeFalse()
            ->and($other->can('changeVisibility', $note))->toBeFalse();
    });
});
