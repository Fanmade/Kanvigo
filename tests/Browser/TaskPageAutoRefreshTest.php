<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('auto-refreshes comments but pauses while the editor is focused', function () {
    config()->set('kanbrio.live_updates.interval_seconds', 1);

    $member = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach([$member->id, $other->id]);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Live task']);

    $this->actingAs($member);

    $page = visit("/ABC-{$task->task_number}");
    $page->assertSee('Live task');

    // A comment posted elsewhere shows up on the next tick.
    $task->comments()->create(['user_id' => $other->id, 'body' => '<p>Echo One</p>']);
    $page->waitForText('Echo One');

    // While the comment editor is focused, polling pauses so a draft isn't lost.
    $page->script("document.querySelector('.ProseMirror')?.focus()");
    $task->comments()->create(['user_id' => $other->id, 'body' => '<p>Echo Two</p>']);
    $page->wait(2.5)->assertDontSee('Echo Two');

    // Blurring the editor lets the next tick catch up.
    $page->script('document.activeElement?.blur()');
    $page->waitForText('Echo Two')
        ->assertNoJavascriptErrors();
});
