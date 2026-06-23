<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\BlockedTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
});

test('a task blocked by an unfinished task is flagged', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::InProgress)->create();
    $task->addBlocker($blocker);

    expect(BlockedTasks::ids([$task->id, $blocker->id]))->toBe([$task->id]);
});

test('a task is no longer flagged once its blocker is done', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::Done)->create();
    $task->addBlocker($blocker);

    expect(BlockedTasks::ids([$task->id]))->toBe([]);
});

test('a task blocked by an incomplete sibling task clears once that blocker is done', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    expect(BlockedTasks::ids([$task->id]))->toBe([$task->id]);

    $blocker->status = Status::Done;
    $blocker->save();

    expect(BlockedTasks::ids([$task->id]))->toBe([]);
});

test('the project board renders a blocked indicator', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSeeHtml('data-test="blocked-'.$task->id.'"')
        ->assertDontSeeHtml('data-test="blocked-'.$blocker->id.'"');
});

test('the global board renders a blocked indicator', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    Livewire::actingAs($this->member)
        ->test(Board::class)
        ->assertSeeHtml('data-test="blocked-'.$task->id.'"');
});

test('moving a blocker to done clears the dependent indicator on the board', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSeeHtml('data-test="blocked-'.$task->id.'"')
        ->call('moveTask', $blocker->id, Status::Done->value)
        ->assertDontSeeHtml('data-test="blocked-'.$task->id.'"');
});
