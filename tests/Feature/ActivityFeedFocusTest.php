<?php

use App\Livewire\Activity\ActivityFeed;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A task whose activity history is longer than one page, so its oldest entries
 * fall outside the default {@see ActivityFeed::PER_PAGE} window.
 *
 * @return array{0: User, 1: Task}
 */
function taskWithLongHistory(): array
{
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();

    foreach (range(1, ActivityFeed::PER_PAGE + 5) as $ignored) {
        seedActivity($task, 'status_changed');
    }

    return [$member, $task];
}

it('stays collapsed with no deep-link', function () {
    [$member, $task] = taskWithLongHistory();

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->assertSet('collapsed', true)
        ->assertSet('focusSequence', null);
});

it('opens and reveals a deep-linked entry that is outside the default window', function () {
    [$member, $task] = taskWithLongHistory();

    // The very first ("created") entry — well past the first page.
    $oldest = $task->activities()->orderBy('sequence')->first();

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task, 'focus' => $oldest->sequence])
        ->assertSet('collapsed', false)
        ->assertSet('focusSequence', $oldest->sequence)
        ->assertSeeHtml('id="log-'.$oldest->sequence.'"')
        ->assertSeeHtml('data-test="focused-activity"');
});

it('focuses an entry on the same page via the focus-activity-log event', function () {
    [$member, $task] = taskWithLongHistory();
    $oldest = $task->activities()->orderBy('sequence')->first();

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->assertSet('collapsed', true)
        ->dispatch('focus-activity-log', sequence: $oldest->sequence)
        ->assertSet('collapsed', false)
        ->assertSet('focusSequence', $oldest->sequence)
        ->assertSeeHtml('id="log-'.$oldest->sequence.'"');
});

it('ignores a deep link to an entry that does not exist', function () {
    [$member, $task] = taskWithLongHistory();

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task, 'focus' => 9999])
        ->assertSet('collapsed', true)
        ->assertSet('focusSequence', null);
});
