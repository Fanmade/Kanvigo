<?php

use App\Enums\Status;
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
    joinProject($this->project, $this->member);

    $this->view = fn () => Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => 'ABC']);
});

it('refreshes the task lists on a live-updates tick', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Original']);

    $component = ($this->view)()->assertSee('Original');

    $task->update(['title' => 'Renamed Externally']); // changed elsewhere

    $component->dispatch('live-refresh')
        ->assertSee('Renamed Externally');
});

it('renders the poll driver and the live-updates toggle on the project overview', function () {
    ($this->view)()
        ->assertSeeHtml('data-test="live-refresh"')
        ->assertSeeHtml('data-test="live-updates-toggle"');
});
