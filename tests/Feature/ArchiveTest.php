<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->parent = Task::factory()->for($this->project)->create();
});

/*
|--------------------------------------------------------------------------
| Archivable trait
|--------------------------------------------------------------------------
*/

test('archiving a task flags it and records the activity', function () {
    $task = Task::factory()->for($this->project)->status(Status::Done)->create();

    expect($task->isArchived())->toBeFalse();

    $task->archive();

    expect($task->fresh()->isArchived())->toBeTrue()
        ->and($task->archived_at)->not->toBeNull()
        ->and($task->status)->toBe(Status::Done)
        ->and($task->activities()->where('action', 'archived')->count())->toBe(1);
});

test('unarchiving a task restores it and records the activity', function () {
    $task = Task::factory()->for($this->project)->archived()->create();

    $task->unarchive();

    expect($task->fresh()->isArchived())->toBeFalse()
        ->and($task->activities()->where('action', 'unarchived')->count())->toBe(1);
});

test('archive and unarchive are idempotent', function () {
    $task = Task::factory()->for($this->project)->create();

    $task->unarchive();
    expect($task->activities()->where('action', 'unarchived')->count())->toBe(0);

    $task->archive();
    $task->archive();
    expect($task->activities()->where('action', 'archived')->count())->toBe(1);
});

test('the notArchived and archived scopes filter by archive state', function () {
    $active = Task::factory()->for($this->project)->create();
    $archived = Task::factory()->for($this->project)->archived()->create();

    expect(Task::query()->notArchived()->pluck('id'))->toContain($active->id)->not->toContain($archived->id)
        ->and(Task::query()->archived()->pluck('id'))->toContain($archived->id)->not->toContain($active->id);
});

/*
|--------------------------------------------------------------------------
| Global board
|--------------------------------------------------------------------------
*/

test('the global board hides archived tasks until the toggle is on', function () {
    Task::factory()->for($this->project)->create(['title' => 'Active task']);
    Task::factory()->for($this->project)->archived()->create(['title' => 'Archived task']);

    Livewire::actingAs($this->member)
        ->test(Board::class)
        ->assertSee('Active task')
        ->assertDontSee('Archived task')
        ->set('showArchived', true)
        ->assertSee('Archived task');
});

test('a task can be archived and unarchived from the global board', function () {
    $task = Task::factory()->for($this->project)->status(Status::Done)->create();

    $component = Livewire::actingAs($this->member)
        ->test(Board::class)
        ->call('archiveTask', $task->id);

    expect($task->fresh()->isArchived())->toBeTrue();

    $component->set('showArchived', true)->call('unarchiveTask', $task->id);

    expect($task->fresh()->isArchived())->toBeFalse();
});

test('archiving a task is forbidden in a project the user cannot access', function () {
    $foreign = Project::factory()->create();
    $task = Task::factory()->for($foreign)->create();

    Livewire::actingAs($this->member)
        ->test(Board::class)
        ->call('archiveTask', $task->id)
        ->assertForbidden();

    expect($task->fresh()->isArchived())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Project board
|--------------------------------------------------------------------------
*/

test('the project board hides archived tasks until the toggle is on', function () {
    Task::factory()->for($this->project)->create(['title' => 'Active task']);
    Task::factory()->for($this->project)->archived()->create(['title' => 'Archived task']);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSee('Active task')
        ->assertDontSee('Archived task')
        ->set('showArchived', true)
        ->assertSee('Archived task');
});

test('a task can be archived from the project board', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('archiveTask', $task->id);

    expect($task->fresh()->isArchived())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Project overview (root tasks)
|--------------------------------------------------------------------------
*/

test('the project overview hides archived root tasks until the toggle is on', function () {
    Task::factory()->for($this->project)->archived()->create(['title' => 'Archived root task']);

    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => 'ABC'])
        ->assertDontSee('Archived root task')
        ->set('showArchived', true)
        ->assertSee('Archived root task');
});

test('a root task can be archived and unarchived from the project overview', function () {
    $component = Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => 'ABC'])
        ->call('archiveTask', $this->parent->id);

    expect($this->parent->fresh()->isArchived())->toBeTrue()
        ->and($this->parent->activities()->where('action', 'archived')->count())->toBe(1);

    $component->call('unarchiveTask', $this->parent->id);

    expect($this->parent->fresh()->isArchived())->toBeFalse();
});

test('archiving a root task is forbidden for a non-member', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(ProjectShow::class, ['short_name' => 'ABC'])
        ->assertForbidden();
});
