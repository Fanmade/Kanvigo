<?php

use App\Enums\Status;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->project->members()->attach($this->user);
});

it('lists in-progress and to-do tasks before completed ones', function () {
    $inProgress = Task::factory()->for($this->project)->status(Status::InProgress)->create(['title' => 'Active work']);
    $inProgress->assignees()->attach($this->user);

    $done = Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Finished work']);
    $done->assignees()->attach($this->user);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertOk()
        ->assertSee('Active work')
        ->assertDontSee('Finished work');
});

it('lists unassigned to-do tasks in the user projects so they can be picked up', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Up for grabs']);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertOk()
        ->assertSee('Up for grabs');
});

it('hides unassigned in-progress tasks', function () {
    Task::factory()->for($this->project)->status(Status::InProgress)->create(['title' => 'Someone is on it']);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertOk()
        ->assertDontSee('Someone is on it');
});

it('hides to-do tasks assigned to other people', function () {
    $other = User::factory()->create();
    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Taken work'])
        ->assignees()->attach($other);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertOk()
        ->assertDontSee('Taken work');
});

it('hides tasks from projects the user is not a member of', function () {
    $foreignProject = Project::factory()->create();
    Task::factory()->for($foreignProject)->status(Status::ToDo)->create(['title' => 'Not my project']);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertOk()
        ->assertDontSee('Not my project');
});

it('orders active tasks with in-progress first, then to-do', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create()->assignees()->attach($this->user);
    Task::factory()->for($this->project)->status(Status::InProgress)->create()->assignees()->attach($this->user);

    $active = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->activeTasks();

    expect($active->first()->status)->toBe(Status::InProgress)
        ->and($active->last()->status)->toBe(Status::ToDo);
});

it('counts assigned tasks per status and projects', function () {
    Task::factory()->for($this->project)->status(Status::InProgress)->create()->assignees()->attach($this->user);
    Task::factory()->for($this->project)->status(Status::InProgress)->create()->assignees()->attach($this->user);
    Task::factory()->for($this->project)->status(Status::Done)->create()->assignees()->attach($this->user);

    $component = Livewire::actingAs($this->user)->test(Dashboard::class);

    $counts = $component->instance()->statusCounts()->keyBy(fn ($s) => $s['status']->value);

    expect($counts[Status::InProgress->value]['count'])->toBe(2)
        ->and($counts[Status::Done->value]['count'])->toBe(1)
        ->and($counts[Status::Planned->value]['count'])->toBe(0)
        ->and($component->instance()->projectCount())->toBe(1);
});

it('caps the active task list at the render limit', function () {
    Task::factory()
        ->count(55)
        ->for($this->project)
        ->status(Status::ToDo)
        ->create()
        ->each(fn (Task $task) => $task->assignees()->attach($this->user));

    $active = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->activeTasks();

    expect($active)->toHaveCount(50);
});

it('builds a 14-day completion series from the user activity', function () {
    $task = Task::factory()->for($this->project)->create();

    // Two completions today, one seven days ago.
    $task->activities()->create(['user_id' => $this->user->id, 'action' => 'status_changed', 'field' => 'status', 'new_value' => Status::Done->value]);
    $task->activities()->create(['user_id' => $this->user->id, 'action' => 'status_changed', 'field' => 'status', 'new_value' => Status::Done->value]);

    $old = $task->activities()->create(['user_id' => $this->user->id, 'action' => 'status_changed', 'field' => 'status', 'new_value' => Status::Done->value]);
    $old->forceFill(['created_at' => now()->subDays(7)])->save();

    $progress = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->progress();

    expect($progress)->toHaveCount(14)
        ->and(collect($progress)->last()['count'])->toBe(2)
        ->and(collect($progress)->firstWhere('date', now()->subDays(7)->toDateString())['count'])->toBe(1);
});
