<?php

use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create();

    $this->mountTask = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ]);
});

it('caps and scrolls the task description', function () {
    $this->task->update(['description' => 'A task description.']);

    ($this->mountTask)()
        ->assertSeeHtml('max-h-96 overflow-y-auto');
});

it('changes the task status inline and logs the transition', function () {
    ($this->mountTask)()
        ->set('status', Status::Done->value);

    expect($this->task->fresh()->status)->toBe(Status::Done);

    assertDatabaseHas('activities', [
        'subject_type' => $this->task->getMorphClass(),
        'subject_id' => $this->task->id,
        'action' => 'status_changed',
        'old_value' => Status::Planned->value,
        'new_value' => Status::Done->value,
    ]);
});

it('ignores an invalid status without recording an activity', function () {
    ($this->mountTask)()
        ->set('status', 'NotAStatus');

    expect($this->task->fresh()->status)->toBe(Status::Planned)
        ->and($this->task->activities()->where('action', 'status_changed')->count())->toBe(0);
});

it('enters edit mode populating the form, then saves and exits', function () {
    $this->task->update(['title' => 'Old title']);

    ($this->mountTask)()
        ->call('edit')
        ->assertSet('editing', true)
        ->assertSet('title', 'Old title')
        ->set('title', 'New title')
        ->call('save')
        ->assertSet('editing', false);

    expect($this->task->fresh()->title)->toBe('New title');
});

it('rejects an empty task title', function () {
    ($this->mountTask)()
        ->call('edit')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('rejects a task title longer than 255 characters', function () {
    ($this->mountTask)()
        ->call('edit')
        ->set('title', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['title' => 'max']);
});
