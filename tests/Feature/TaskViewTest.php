<?php

use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Story;
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
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();

    $this->mountTask = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'story_number' => $this->story->story_number,
            'task_number' => $this->task->task_number,
        ]);
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
