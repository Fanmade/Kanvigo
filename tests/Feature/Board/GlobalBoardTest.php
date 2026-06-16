<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows tasks from visible projects and hides others', function () {
    $user = User::factory()->create();

    $mine = Project::factory()->create();
    $mine->members()->attach($user);
    Task::factory()->for(Story::factory()->for($mine))->create(['title' => 'Visible task']);

    $foreign = Project::factory()->create();
    Task::factory()->for(Story::factory()->for($foreign))->create(['title' => 'Hidden task']);

    Livewire::actingAs($user)
        ->test(Board::class)
        ->assertOk()
        ->assertSee('Visible task')
        ->assertDontSee('Hidden task');
});

it('moves a task on the global board and logs it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $task = Task::factory()->for(Story::factory()->for($project))->status(Status::Planned)->create();

    Livewire::actingAs($user)
        ->test(Board::class)
        ->call('moveTask', $task->id, Status::Done->value);

    expect($task->fresh()->status)->toBe(Status::Done)
        ->and($task->activities()->where('action', 'status_changed')->count())->toBe(1);
});

it('forbids moving a task in a project the user cannot access', function () {
    $user = User::factory()->create();
    $foreign = Project::factory()->create();
    $task = Task::factory()->for(Story::factory()->for($foreign))->status(Status::Planned)->create();

    Livewire::actingAs($user)
        ->test(Board::class)
        ->call('moveTask', $task->id, Status::Done->value)
        ->assertForbidden();

    expect($task->fresh()->status)->toBe(Status::Planned);
});
