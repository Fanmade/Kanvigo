<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows tasks from visible projects and hides others', function () {
    $user = User::factory()->create();

    $mine = Project::factory()->create();
    joinProject($mine, $user);
    Task::factory()->for($mine)->create(['title' => 'Visible task']);

    $foreign = Project::factory()->create();
    Task::factory()->for($foreign)->create(['title' => 'Hidden task']);

    Livewire::actingAs($user)
        ->test(Board::class)
        ->assertOk()
        ->assertSee('Visible task')
        ->assertDontSee('Hidden task');
});

it('moves a task on the global board and logs it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);
    $task = Task::factory()->for($project)->status(Status::Planned)->create();

    Livewire::actingAs($user)
        ->test(Board::class)
        ->call('moveTask', $task->id, Status::Done->value);

    expect($task->fresh()->status)->toBe(Status::Done)
        ->and($task->activities()->where('action', 'status_changed')->count())->toBe(1);
});

it('forbids moving a task in a project the user cannot access', function () {
    $user = User::factory()->create();
    $foreign = Project::factory()->create();
    $task = Task::factory()->for($foreign)->status(Status::Planned)->create();

    Livewire::actingAs($user)
        ->test(Board::class)
        ->call('moveTask', $task->id, Status::Done->value)
        ->assertForbidden();

    expect($task->fresh()->status)->toBe(Status::Planned);
});

it('keeps canceled tasks off the global board', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Active work']);
    Task::factory()->for($project)->canceled()->create(['title' => 'Abandoned work']);

    Livewire::actingAs($user)
        ->test(Board::class)
        ->assertOk()
        ->assertSee('Active work')
        ->assertDontSee('Abandoned work');
});
