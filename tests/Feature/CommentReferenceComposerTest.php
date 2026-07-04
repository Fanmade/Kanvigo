<?php

use App\Livewire\Activity\ActivityFeed;
use App\Livewire\Comments\CommentList;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A project member, a task, and one referenceable activity entry on it.
 *
 * @return array{0: User, 1: Task, 2: string}
 */
function memberTaskAndEntry(): array
{
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'status_changed');

    return [$member, $task, $entry->reference];
}

it('references an entry in the composer when "Discuss" is triggered', function () {
    [$member, $task, $reference] = memberTaskAndEntry();

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->dispatch('discuss-activity', reference: $reference)
        ->assertSet('referencedActivities', [$reference])
        ->assertSet('collapsed', false)
        ->assertDispatched('open-composer')
        ->assertSeeHtml('data-test="comment-reference"');
});

it('does not add the same reference twice', function () {
    [$member, $task, $reference] = memberTaskAndEntry();

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->dispatch('discuss-activity', reference: $reference)
        ->dispatch('discuss-activity', reference: $reference)
        ->assertSet('referencedActivities', [$reference]);
});

it('ignores an unknown reference', function () {
    [$member, $task] = memberTaskAndEntry();

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->dispatch('discuss-activity', reference: 'KAN-999-log-1')
        ->assertSet('referencedActivities', []);
});

it('removes a reference before posting', function () {
    [$member, $task, $reference] = memberTaskAndEntry();

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->dispatch('discuss-activity', reference: $reference)
        ->call('removeReference', $reference)
        ->assertSet('referencedActivities', []);
});

it('attaches the referenced entries to the posted comment and clears them', function () {
    [$member, $task, $reference] = memberTaskAndEntry();
    $entryId = $task->activities()->where('action', 'status_changed')->value('id');

    $component = Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->dispatch('discuss-activity', reference: $reference)
        ->set('body', '<p>Why this change?</p>')
        ->call('addComment')
        ->assertSet('referencedActivities', []);

    $comment = $task->comments()->whereNull('parent_id')->latest()->first();

    expect($comment->activities->pluck('id')->all())->toBe([$entryId]);
});

it('shows a Discuss action on each entry for a task feed', function () {
    [$member, $task, $reference] = memberTaskAndEntry();

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->call('focusOnSequence', 1) // expand so rows render
        ->assertSeeHtml('data-test="discuss-activity"')
        ->assertSeeHtml($reference);
});
