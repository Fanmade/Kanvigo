<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Stories\StoryView;
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

    $this->mountStory = fn () => Livewire::actingAs($this->member)
        ->test(StoryView::class, ['short_name' => 'ABC', 'story_number' => $this->story->story_number]);
});

it('caps and scrolls the story description', function () {
    $this->story->update(['description' => 'A story description.']);

    ($this->mountStory)()->assertSeeHtml('max-h-96 overflow-y-auto');
});

it('shows story completeness based on its subtasks', function () {
    Task::factory()->for($this->story)->status(Status::Done)->create();
    Task::factory()->for($this->story)->status(Status::ToDo)->count(2)->create();

    ($this->mountStory)()->assertSee('1 / 3');
});

it('does not show a progress bar for a story with no tasks', function () {
    ($this->mountStory)()->assertDontSee('0 / 0');
});

it('reflects newly created tasks in the completeness', function () {
    Task::factory()->for($this->story)->status(Status::Done)->create();

    ($this->mountStory)()
        ->assertSee('1 / 1')
        ->call('openTaskModal')
        ->set('taskTitle', 'New task')
        ->set('taskStatus', Status::ToDo->value)
        ->call('createTask')
        ->assertSee('1 / 2');
});

it('enters edit mode and populates the form from the story', function () {
    $this->story->update(['title' => 'Original title', 'description' => 'Some description']);

    ($this->mountStory)()
        ->call('edit')
        ->assertSet('editing', true)
        ->assertSet('title', 'Original title')
        ->assertSet('description', 'Some description');
});

it('saves story changes and leaves edit mode', function () {
    ($this->mountStory)()
        ->call('edit')
        ->set('title', 'Renamed story')
        ->call('save')
        ->assertSet('editing', false);

    expect($this->story->fresh()->title)->toBe('Renamed story');
});

it('rejects an empty story title', function () {
    ($this->mountStory)()
        ->call('edit')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);

    expect($this->story->fresh()->title)->not->toBe('');
});

it('rejects a story title longer than 255 characters', function () {
    ($this->mountStory)()
        ->call('edit')
        ->set('title', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['title' => 'max']);
});

it('records an activity when the story priority changes inline', function () {
    ($this->mountStory)()
        ->set('priority', Priority::Highest->value);

    expect($this->story->fresh()->priority)->toBe(Priority::Highest);

    assertDatabaseHas('activities', [
        'subject_type' => $this->story->getMorphClass(),
        'subject_id' => $this->story->id,
        'action' => 'priority_changed',
        'old_value' => (string) Priority::Medium->value,
        'new_value' => (string) Priority::Highest->value,
    ]);
});

it('opens the task modal in a ready state', function () {
    ($this->mountStory)()
        ->call('openTaskModal')
        ->assertSet('showTaskModal', true)
        ->assertSet('taskPriority', Priority::Medium->value);
});

it('rejects a new task with an out-of-range priority', function () {
    ($this->mountStory)()
        ->call('openTaskModal')
        ->set('taskTitle', 'Valid title')
        ->set('taskPriority', 999)
        ->call('createTask')
        ->assertHasErrors(['taskPriority']);

    expect($this->story->tasks()->count())->toBe(0);
});

it('rejects a new task with an invalid status', function () {
    ($this->mountStory)()
        ->call('openTaskModal')
        ->set('taskTitle', 'Valid title')
        ->set('taskStatus', 'NotAStatus')
        ->call('createTask')
        ->assertHasErrors(['taskStatus']);

    expect($this->story->tasks()->count())->toBe(0);
});

it('assigns members to the story, auto-subscribes them, and logs the change', function () {
    $other = User::factory()->create();
    $this->project->members()->attach($other);

    ($this->mountStory)()
        ->set('assigneeIds', [$this->member->id, $other->id]);

    expect($this->story->assignees()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$this->member->id, $other->id])
        ->and($this->story->isSubscribedBy($this->member))->toBeTrue()
        ->and($this->story->isSubscribedBy($other))->toBeTrue()
        ->and($this->story->activities()->where('action', 'assignee_changed')->count())->toBe(1);
});

it('logs an assignee change when an assignee is removed', function () {
    $this->story->assignees()->attach($this->member->id);

    ($this->mountStory)()
        ->set('assigneeIds', []);

    expect($this->story->assignees()->count())->toBe(0)
        ->and($this->story->activities()->where('action', 'assignee_changed')->count())->toBe(1);
});
