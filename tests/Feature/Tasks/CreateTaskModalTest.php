<?php

use App\Enums\Status;
use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
});

it('preselects the project passed as context', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->assertSet('show', true)
        ->assertSet('projectId', $this->project->id);
});

it('preselects the only available project when opened without context', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open')
        ->assertSet('projectId', $this->project->id);
});

it('only lists projects the user is a member of', function () {
    Project::factory()->create(['short_name' => 'XYZ']);

    $projects = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->instance()->projects();

    expect($projects->pluck('id')->all())->toBe([$this->project->id]);
});

it('creates a nested task under the chosen parent', function () {
    $parent = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $parent->id)
        ->assertSet('parentId', $parent->id)
        ->set('title', 'Nested work')
        ->call('save');

    $child = $this->project->tasks()->where('title', 'Nested work')->first();

    expect($child->parent_id)->toBe($parent->id);
});

it('offers only depth-eligible, active tasks as parents', function () {
    // max_depth 3: the root (level 1) and its child (level 2) may take a child;
    // the grandchild (level 3) is at the limit. Terminal and archived tasks are
    // never offered as parents.
    $root = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Root']);
    $child = Task::factory()->for($this->project)->childOf($root)->status(Status::ToDo)->create(['title' => 'Child']);
    $grandchild = Task::factory()->for($this->project)->childOf($child)->status(Status::ToDo)->create(['title' => 'Grandchild']);
    $done = Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Done']);
    $archived = Task::factory()->for($this->project)->archived()->create(['title' => 'Archived']);

    $options = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->set('projectId', $this->project->id)
        ->instance()->parentOptions();

    expect(array_keys($options))->toContain($root->id, $child->id)
        ->and(array_keys($options))->not->toContain($grandchild->id, $done->id, $archived->id);
});

it('resets the parent and assignee selection when the project changes', function () {
    $parent = Task::factory()->for($this->project)->create();
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $other->members()->attach($this->member);

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $parent->id)
        ->assertSet('parentId', $parent->id)
        ->set('assigneeIds', [$this->member->id])
        ->set('projectId', $other->id)
        ->assertSet('parentId', null)
        ->assertSet('assigneeIds', []);
});

it('stages a typed tag and attaches it to the created task', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Tagged')
        ->set('tagQuery', 'urgent')
        ->call('createDraftTag')
        ->assertSet('tagNames', ['urgent'])
        ->assertSet('tagQuery', '')
        ->call('save');

    $task = $this->project->tasks()->where('title', 'Tagged')->first();

    expect($task->tags->pluck('name')->all())->toContain('urgent');
});

it('does not stage the same tag twice', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('tagQuery', 'urgent')
        ->call('createDraftTag')
        ->set('tagQuery', 'URGENT')
        ->call('createDraftTag')
        ->assertSet('tagNames', ['urgent']);
});

it('suggests an existing tag matching the query', function () {
    Tag::firstOrCreate(['name' => 'backend']);

    $suggestions = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('tagQuery', 'back')
        ->instance()
        ->tagSuggestions();

    expect($suggestions->pluck('name')->all())->toContain('backend');
});

it('assigns chosen project members and subscribes them', function () {
    $assignee = User::factory()->create();
    $this->project->members()->attach($assignee);

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Assigned work')
        ->set('assigneeIds', [$assignee->id])
        ->call('save');

    $task = $this->project->tasks()->where('title', 'Assigned work')->first();

    expect($task->assignees->pluck('id')->all())->toContain($assignee->id)
        ->and($task->subscribers->pluck('id')->all())->toContain($assignee->id);
});

it('ignores assignees who are not members of the project', function () {
    $stranger = User::factory()->create();

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Guarded')
        ->set('assigneeIds', [$stranger->id])
        ->call('save');

    $task = $this->project->tasks()->where('title', 'Guarded')->first();

    expect($task->assignees)->toHaveCount(0);
});

it('renders a markdown preview of the description on demand', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->assertSet('showPreview', false)
        ->set('description', '# Plan')
        ->set('showPreview', true)
        ->assertSeeHtml('<h1>Plan</h1>');
});

it('dispatches task-created and closes after a successful save', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Fresh task')
        ->call('save')
        ->assertDispatched('task-created')
        ->assertSet('show', false);
});

it('rejects a parent task at the maximum nesting depth', function () {
    config(['kanbrio.tasks.max_depth' => 2]);
    $root = Task::factory()->for($this->project)->create();
    $child = Task::factory()->for($this->project)->childOf($root)->create(); // depth 2 = max

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Too deep')
        ->set('parentId', $child->id)
        ->call('save')
        ->assertStatus(422);

    expect($child->children()->count())->toBe(0);
});

it('rejects creating a task in a project the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->set('projectId', $other->id)
        ->set('title', 'Sneaky')
        ->call('save')
        ->assertStatus(404);
});
