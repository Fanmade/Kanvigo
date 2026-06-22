<?php

use App\Enums\Priority;
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

it('treats an empty parent selection as a top-level task', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create();

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('parentId', '') // what the "None (top-level task)" option sends
        ->assertSet('parentId', null)
        ->set('title', 'Top level')
        ->call('save');

    expect($this->project->tasks()->where('title', 'Top level')->first()->parent_id)->toBeNull();
});

it('keeps a terminal parent selectable when a subtask is added to it', function () {
    $done = Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Shipped']);

    $options = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $done->id)
        ->instance()
        ->parentOptions();

    expect(array_keys($options))->toContain($done->id);
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

it('stages a new tag through the color picker and attaches it to the created task', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Tagged')
        ->set('tagQuery', 'urgent')
        ->call('openTagColorModal')
        ->assertSet('showTagColorModal', true)
        ->assertSet('newTagName', 'urgent')
        ->call('confirmNewTag')
        ->assertSet('tagNames', ['urgent'])
        ->assertSet('tagQuery', '')
        ->assertSet('showTagColorModal', false)
        ->call('save');

    $task = $this->project->tasks()->where('title', 'Tagged')->first();

    expect($task->tags->pluck('name')->all())->toContain('urgent');
});

it('creates a brand-new tag with the chosen color', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Colored')
        ->set('tagQuery', 'backend')
        ->call('openTagColorModal')
        ->set('newTagColor', 'indigo')
        ->call('confirmNewTag')
        ->assertSet('tagNames', ['backend'])
        ->call('save');

    expect(Tag::where('name', 'backend')->first()->color)->toBe('indigo');
});

it('stages an existing tag directly on enter, skipping the color picker', function () {
    Tag::firstOrCreate(['name' => 'backend'], ['color' => 'sky']);

    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('tagQuery', 'backend')
        ->call('tagEnter', 'backend')
        ->assertSet('showTagColorModal', false)
        ->assertSet('tagNames', ['backend']);
});

it('does not stage the same tag twice', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('tagQuery', 'urgent')
        ->call('tagEnter', 'urgent')
        ->call('confirmNewTag')
        ->set('tagQuery', 'URGENT')
        ->call('tagEnter', 'URGENT')
        ->call('confirmNewTag')
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

it('stages the current user with one click and assigns them on save', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->assertSet('assigneeIds', [])
        ->call('assignToMe')
        ->assertSet('assigneeIds', [$this->member->id])
        ->call('assignToMe') // idempotent
        ->assertSet('assigneeIds', [$this->member->id])
        ->set('title', 'Mine')
        ->call('save');

    $task = $this->project->tasks()->where('title', 'Mine')->first();

    expect($task->assignees->pluck('id')->all())->toBe([$this->member->id])
        ->and($task->subscribers->pluck('id')->all())->toContain($this->member->id);
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

it('refreshes the page and shows a toast linking to the new task', function () {
    $component = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Fresh task')
        ->call('save')
        ->assertSet('show', false)
        ->assertDispatched('task-created');

    $task = $this->project->tasks()->where('title', 'Fresh task')->first();
    $url = route('task.show', ['short_name' => $this->project->short_name, 'task_number' => $task->task_number]);

    $component->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['link']['href'] ?? null) === $url
        && ($params['link']['text'] ?? null) === $task->reference);
});

it('stays open and keeps the context when create another is on', function () {
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    $component = Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $parent->id)
        ->set('createAnother', true)
        ->set('priority', Priority::High->value)
        ->set('status', Status::ToDo->value)
        ->set('title', 'First')
        ->set('description', 'Some notes')
        ->call('save')
        ->assertSet('show', true)
        ->assertSet('projectId', $this->project->id)
        ->assertSet('parentId', $parent->id)
        ->assertSet('priority', Priority::High->value)
        ->assertSet('status', Status::ToDo->value)
        ->assertSet('createAnother', true)
        ->assertSet('title', '')
        ->assertSet('description', '')
        ->assertDispatched('create-task-focus-title');

    // The next task can be entered straight away.
    $component->set('title', 'Second')->call('save');

    expect($this->project->tasks()->pluck('title')->all())->toContain('First', 'Second')
        ->and($this->project->tasks()->where('title', 'Second')->first()->parent_id)->toBe($parent->id);
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
