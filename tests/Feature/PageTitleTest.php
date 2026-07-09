<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withOwner($this->user)->create([
        'short_name' => 'KAN',
        'title' => 'Kanvigo',
    ]);
});

it('leads the project page title with the short name', function () {
    $this->actingAs($this->user)
        ->get(route('project.show', ['short_name' => 'KAN']))
        ->assertOk()
        ->assertSee('<title>', false)
        ->assertSee('KAN · Kanvigo', false);
});

it('leads the project board title with the short name', function () {
    $this->actingAs($this->user)
        ->get(route('project.board', ['short_name' => 'KAN']))
        ->assertOk()
        ->assertSee('KAN · Board', false);
});

it('leads the project tags title with the short name', function () {
    $this->actingAs($this->user)
        ->get(route('project.tags', ['short_name' => 'KAN']))
        ->assertOk()
        ->assertSee('KAN · Tags', false);
});

it('leads the project task-types title with the short name', function () {
    $this->actingAs($this->user)
        ->get(route('project.task-types', ['short_name' => 'KAN']))
        ->assertOk()
        ->assertSee('KAN · Task types', false);
});

it('shows the task reference and title in the tab title', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Fix the board']);

    $this->actingAs($this->user)
        ->get(route('task.show', ['short_name' => 'KAN', 'task_number' => $task->task_number]))
        ->assertOk()
        ->assertSee($task->reference.' · Fix the board', false);
});
