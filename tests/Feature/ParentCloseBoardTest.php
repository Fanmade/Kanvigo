<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
use App\Enums\Status;
use App\Livewire\Projects\ProjectBoard;
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

    $this->parent = Task::factory()->for($this->project)->status(Status::InProgress)->create();
    $this->child = Task::factory()->for($this->project)->childOf($this->parent)->status(Status::ToDo)->create();

    $this->board = fn () => Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC']);
});

it('prompts to close the parent after dragging the last subtask to Done (ask)', function () {
    ($this->board)()
        ->call('moveTask', $this->child->id, Status::Done->value)
        ->assertSet('confirmingParentClose', true)
        ->assertSet('parentCloseReference', $this->parent->reference);

    expect($this->child->fresh()->status)->toBe(Status::Done)
        ->and($this->parent->fresh()->status)->toBe(Status::InProgress);
});

it('closes the parent from the board when the prompt is confirmed', function () {
    ($this->board)()
        ->call('moveTask', $this->child->id, Status::Done->value)
        ->call('confirmParentClose')
        ->assertSet('confirmingParentClose', false);

    expect($this->parent->fresh()->status)->toBe(Status::Done);
});

it('auto-closes the parent from the board without prompting under "always"', function () {
    $this->member->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Always->value);

    ($this->board)()
        ->call('moveTask', $this->child->id, Status::Done->value)
        ->assertSet('confirmingParentClose', false);

    expect($this->parent->fresh()->status)->toBe(Status::Done);
});
