<?php

use App\Enums\Status;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();
});

it('moves a task to a new status and logs the change', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);

    expect($this->task->fresh()->status)->toBe(Status::Done);

    $activity = $this->task->activities()->where('action', 'status_changed')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->old_value)->toBe(Status::Planned->value)
        ->and($activity->new_value)->toBe(Status::Done->value)
        ->and($activity->user_id)->toBe($this->member->id);
});

it('ignores an invalid status', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, 'NotAStatus');

    expect($this->task->fresh()->status)->toBe(Status::Planned)
        ->and($this->task->activities()->where('action', 'status_changed')->count())->toBe(0);
});

it('forbids non-members from opening the board', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertForbidden();
});

it('groups consecutive tasks of the same story in a column', function () {
    Task::factory()->for($this->story)->status(Status::Planned)->create();

    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC']);

    $columns = $component->instance()->columns();
    $planned = collect($columns)->firstWhere('status', Status::Planned);

    expect($planned['groups'])->toHaveCount(1)
        ->and($planned['groups'][0]['tasks'])->toHaveCount(2)
        ->and($planned['groups'][0]['story']->id)->toBe($this->story->id);
});

it('creates a story from the board', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('storyTitle', 'New Story')
        ->call('createStory');

    expect($this->project->stories()->where('title', 'New Story')->exists())->toBeTrue();
});
