<?php

use App\Livewire\Comments\CommentList;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders a reference card linking to the entry on the posted comment', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'status_changed');

    $comment = $task->comments()->create(['user_id' => $member->id, 'body' => '<p>why?</p>']);
    $comment->activities()->attach($entry->id);

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->assertSeeHtml('data-test="comment-activity-reference"')
        ->assertSeeHtml('?log='.$entry->sequence.'#log-'.$entry->sequence)
        ->assertSeeHtml($entry->reference);
});

it('links a cross-task reference to the referenced entry\'s own task', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $taskA = Task::factory()->for($project)->create();
    $taskB = Task::factory()->for($project)->create();
    $entryOnB = seedActivity($taskB, 'status_changed');

    $comment = $taskA->comments()->create(['user_id' => $member->id, 'body' => '<p>see other task</p>']);
    $comment->activities()->attach($entryOnB->id);

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $taskA])
        // The link targets task B (the entry's task), not the comment's task A.
        ->assertSeeHtml('KAN-'.$taskB->task_number.'?log='.$entryOnB->sequence)
        ->assertSeeHtml($entryOnB->reference);
});

it('renders a referenced comment once, not as its own reply (KAN-339)', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'commented');

    $comment = $task->comments()->create(['user_id' => $member->id, 'body' => '<p>test</p>']);
    $comment->activities()->attach($entry->id);

    $html = Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->html();

    // One comment, no replies → its references block renders once and the
    // comment is not nested as a reply of anything.
    expect(substr_count($html, 'data-test="comment-activity-references"'))->toBe(1)
        ->and($html)->not->toContain('wire:key="reply-');
});

it('shows no reference card for a comment without references', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $task->comments()->create(['user_id' => $member->id, 'body' => '<p>plain</p>']);

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->assertDontSeeHtml('data-test="comment-activity-reference"');
});

it('renders reference cards without an N+1 as comments grow', function () {
    $queriesToRender = static function (int $comments): int {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        joinProject($project, $member);
        $host = Task::factory()->for($project)->create();

        // Each comment references an entry on a *different* task, so a missing
        // eager-load would issue one extra subject/project query per card.
        foreach (range(1, $comments) as $ignored) {
            $other = Task::factory()->for($project)->create();
            $entry = seedActivity($other, 'status_changed');
            $comment = $host->comments()->create(['user_id' => $member->id, 'body' => '<p>q</p>']);
            $comment->activities()->attach($entry->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($member)
            ->test(CommentList::class, ['commentable' => $host])
            ->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesToRender(8))->toBeLessThanOrEqual($queriesToRender(2));
});

it('drops the card when the referenced entry is deleted', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'KAN']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'status_changed');
    $comment = $task->comments()->create(['user_id' => $member->id, 'body' => '<p>why?</p>']);
    $comment->activities()->attach($entry->id);

    $entry->delete();

    Livewire::actingAs($member)
        ->test(CommentList::class, ['commentable' => $task])
        ->assertDontSeeHtml('data-test="comment-activity-reference"');
});
