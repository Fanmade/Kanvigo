<?php

use App\Enums\Priority;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Stories\StoryView;
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
    $this->story = Story::factory()->for($this->project)->priority(Priority::Medium)->create();
});

it('creates a story with a chosen priority from the board', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('storyTitle', 'Launch')
        ->set('storyPriority', Priority::High->value)
        ->call('createStory');

    expect($this->project->stories()->where('title', 'Launch')->first()->priority)
        ->toBe(Priority::High);
});

it('defaults the new-task priority to the selected story and creates with it', function () {
    $highStory = Story::factory()->for($this->project)->priority(Priority::Highest)->create();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('openTaskModal', $highStory->id)
        ->assertSet('taskPriority', Priority::Highest->value)
        ->set('taskTitle', 'Ship it')
        ->call('createTask');

    expect($highStory->tasks()->where('title', 'Ship it')->first()->priority)
        ->toBe(Priority::Highest);
});

it('syncs the new-task priority when the story selection changes', function () {
    $lowStory = Story::factory()->for($this->project)->priority(Priority::Low)->create();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('openTaskModal', $this->story->id)
        ->set('taskStoryId', $lowStory->id)
        ->assertSet('taskPriority', Priority::Low->value);
});

it('updates a task priority inline from the task view and logs it', function () {
    $task = Task::factory()->for($this->story)->priority(Priority::Medium)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'story_number' => $this->story->story_number,
            'task_number' => $task->task_number,
        ])
        ->set('priority', Priority::Highest->value);

    expect($task->fresh()->priority)->toBe(Priority::Highest);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'priority_changed',
        'field' => 'priority',
        'new_value' => (string) Priority::Highest->value,
    ]);
});

it('updates a story priority inline from the story view', function () {
    Livewire::actingAs($this->member)
        ->test(StoryView::class, [
            'short_name' => 'ABC',
            'story_number' => $this->story->story_number,
        ])
        ->set('priority', Priority::Lowest->value);

    expect($this->story->fresh()->priority)->toBe(Priority::Lowest);
});
