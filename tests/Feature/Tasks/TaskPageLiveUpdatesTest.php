<?php

use App\Enums\Status;
use App\Livewire\Activity\ActivityFeed;
use App\Livewire\Comments\CommentList;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    $this->taskView = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number]);
});

it('refreshes the task header on a live-updates tick', function () {
    $component = ($this->taskView)();

    $this->task->update(['title' => 'Renamed Externally']); // changed elsewhere

    $component->dispatch('live-refresh')
        ->assertSee('Renamed Externally');
});

it('pulls in comments added elsewhere on a live-updates tick', function () {
    $other = User::factory()->create();

    $component = Livewire::actingAs($this->member)->test(CommentList::class, ['commentable' => $this->task]);

    $this->task->comments()->create(['user_id' => $other->id, 'body' => '<p>Hello from elsewhere</p>']);

    $component->dispatch('live-refresh')
        ->assertSee('Hello from elsewhere');
});

it('pulls in activity recorded elsewhere on a live-updates tick', function () {
    $component = Livewire::actingAs($this->member)->test(ActivityFeed::class, ['subject' => $this->task]);
    $before = $component->instance()->activityCount;

    seedActivity($this->task, 'status_changed', 'status', Status::ToDo->value, Status::Done->value);

    $component->dispatch('live-refresh');

    expect($component->instance()->activityCount)->toBeGreaterThan($before);
});

it('renders the poll driver and the live-updates toggle on the task page', function () {
    ($this->taskView)()
        ->assertSeeHtml('data-test="live-refresh"')
        ->assertSeeHtml('data-test="live-updates-toggle"');
});
