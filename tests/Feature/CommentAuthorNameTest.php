<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->task = Task::factory()->for(Project::factory())->create();
});

it('uses the author name for a comment with a present user', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $comment = $this->task->comments()->create(['user_id' => $user->id, 'body' => 'Hi']);

    expect($comment->authorName())->toBe('Ada Lovelace');
});

it('labels a comment from a removed account as a deleted user', function () {
    $user = User::factory()->create();
    $comment = $this->task->comments()->create(['user_id' => $user->id, 'body' => 'Hi']);

    // Soft-deleting the account leaves user_id set but the relation unresolved.
    $user->delete();

    expect($comment->fresh()->authorName())->toBe('Deleted user');
});

it('labels an author-less comment as system', function () {
    $comment = $this->task->comments()->create(['user_id' => null, 'body' => 'Hi']);

    expect($comment->authorName())->toBe('System');
});
