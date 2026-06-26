<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create();
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

it('moves a task into every other status', function (Status $target) {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, $target->value);

    expect($this->task->fresh()->status)->toBe($target);
})->with([
    'to do' => Status::ToDo,
    'in progress' => Status::InProgress,
    'done' => Status::Done,
]);

it('renders the drag-and-drop wiring and keyboard move options', function () {
    $html = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->html();

    // SortableJS drop zones and draggable cards are wired up.
    expect($html)
        ->toContain('data-task-card')
        ->toContain('data-task-id="'.$this->task->id.'"')
        ->toContain('data-status="'.Status::Planned->value.'"')
        // The keyboard menu offers every status except the task's current one.
        ->toContain('data-test="move-'.$this->task->id.'-'.Status::Done->value.'"')
        ->toContain('data-test="move-'.$this->task->id.'-'.Status::InProgress->value.'"')
        ->not->toContain('data-test="move-'.$this->task->id.'-'.Status::Planned->value.'"');
});

it('shows a root task as a single breadcrumb badge of its own reference', function () {
    $this->task->update(['title' => 'Polished board drag-and-drop']);

    $html = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->html();

    expect($html)
        ->toContain('data-test="crumb-'.$this->task->id.'-self"')
        ->toContain($this->task->reference)           // flat id, e.g. ABC-1
        ->toContain('Polished board drag-and-drop');  // task title, surfaced on hover
});

it('shows a nested task as a breadcrumb from its root ancestor down to itself', function () {
    $this->task->update(['title' => 'Parent task']);
    $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'Child task']);

    $html = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->html();

    expect($html)
        ->toContain('data-test="crumb-'.$child->id.'-'.$this->task->id.'"') // ancestor badge links to the parent
        ->toContain('data-test="crumb-'.$child->id.'-self"')                // the task's own badge
        ->toContain($this->task->reference)                                 // parent flat id, e.g. ABC-1
        ->toContain($child->reference)                                      // child flat id, e.g. ABC-2
        ->toContain('Parent task');                                         // ancestor title, surfaced on hover
});

it('eager-loads breadcrumb ancestors instead of one recursive query per nested card', function () {
    $chains = 6;

    // Independent three-level chains: root -> child -> grandchild.
    foreach (range(1, $chains) as $ignored) {
        $root = Task::factory()->for($this->project)->create();
        $child = Task::factory()->for($this->project)->childOf($root)->create();
        Task::factory()->for($this->project)->childOf($child)->create();
    }

    DB::enableQueryLog();
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->html();
    $recursive = collect(DB::getQueryLog())
        ->filter(static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'recursive'))
        ->count();
    DB::disableQueryLog();

    // There are 2 * $chains nested (non-root) cards; a per-card lazy ancestor
    // lookup would issue at least that many recursive queries. Eager loading
    // keeps it to a handful of batched queries.
    expect($recursive)->toBeLessThan(2 * $chains);
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

it('lists every task of a status in a single flat column', function () {
    Task::factory()->for($this->project)->status(Status::Planned)->create();
    Task::factory()->for($this->project)->status(Status::Planned)->create();

    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC']);

    $planned = collect($component->instance()->columns())->firstWhere('status', Status::Planned);

    expect($planned)->not->toHaveKey('groups')
        ->and($planned['tasks'])->toHaveCount(3);
});

it('orders tasks within a column by their manual position', function () {
    // Positions are assigned on creation, so the column reflects creation order
    // regardless of priority until a card is dragged.
    $first = $this->task; // created in beforeEach
    $second = Task::factory()->for($this->project)->status(Status::Planned)->priority(Priority::Highest)->create();
    $third = Task::factory()->for($this->project)->status(Status::Planned)->priority(Priority::Low)->create();

    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->instance()->columns();

    $ids = collect($columns)->firstWhere('status', Status::Planned)['tasks']
        ->map(fn (Task $task) => $task->id)
        ->all();

    expect($ids)->toBe([$first->id, $second->id, $third->id]);
});

it('assigns each new task a distinct, increasing position', function () {
    // Guards the board order: tasks must never share a position, or midpoint
    // reordering has no room to insert between them.
    $a = Task::factory()->for($this->project)->create();
    $b = Task::factory()->for($this->project)->create();

    expect($a->position)->toBeGreaterThan(0)
        ->and($b->position)->toBeGreaterThan($a->position)
        ->and($this->task->position)->not->toBe($a->position);
});

it('reorders a task within a column and persists the new order', function () {
    $first = $this->task; // position 1
    $second = Task::factory()->for($this->project)->status(Status::Planned)->create();
    $third = Task::factory()->for($this->project)->status(Status::Planned)->create();

    // Drag the third card to the very top: it lands above $first (no card before it).
    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('reorderTask', $third->id, Status::Planned->value, null, $first->id)
        ->instance()->columns();

    $ids = collect($columns)->firstWhere('status', Status::Planned)['tasks']
        ->map(fn (Task $task) => $task->id)
        ->all();

    expect($ids)->toBe([$third->id, $first->id, $second->id])
        ->and($third->fresh()->position)->toBeLessThan($first->fresh()->position);
});

it('reordering a task into another column changes its status and logs it', function () {
    $target = Task::factory()->for($this->project)->status(Status::Done)->create();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('reorderTask', $this->task->id, Status::Done->value, $target->id, null);

    $moved = $this->task->fresh();

    expect($moved->status)->toBe(Status::Done)
        ->and($moved->position)->toBeGreaterThan($target->fresh()->position)
        ->and($this->task->activities()->where('action', 'status_changed')->count())->toBe(1);
});

it('filters the board by priority', function () {
    $this->task->update(['priority' => Priority::Low]);

    Task::factory()->for($this->project)->status(Status::Planned)->priority(Priority::Highest)->create();
    Task::factory()->for($this->project)->status(Status::ToDo)->priority(Priority::Low)->create();

    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('priorityFilter', Priority::Highest->value)
        ->instance()->columns();

    $tasks = collect($columns)->flatMap(fn ($column) => $column['tasks']);

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->priority)->toBe(Priority::Highest);
});

it('filters a single column by its own title search', function () {
    $this->task->update(['title' => 'Polish the board']);
    $other = Task::factory()->for($this->project)->status(Status::Planned)->create(['title' => 'Unrelated work']);

    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('columnSearch.'.Status::Planned->value, 'polish')
        ->instance()->columns();

    $ids = collect($columns)->firstWhere('status', Status::Planned)['tasks']
        ->pluck('id')->all();

    expect($ids)->toBe([$this->task->id])
        ->and($ids)->not->toContain($other->id);
});

it('filters a column by task reference', function () {
    $match = $this->task; // ABC-1
    Task::factory()->for($this->project)->status(Status::Planned)->create();

    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('columnSearch.'.Status::Planned->value, $match->reference)
        ->instance()->columns();

    $ids = collect($columns)->firstWhere('status', Status::Planned)['tasks']
        ->pluck('id')->all();

    expect($ids)->toBe([$match->id]);
});

it('searches each column independently', function () {
    $this->task->update(['title' => 'Keep me planned']);
    $done = Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Finished thing']);

    // A term that only matches the Done card; it must not empty the Planned column.
    $columns = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('columnSearch.'.Status::Done->value, 'finished')
        ->instance()->columns();

    $planned = collect($columns)->firstWhere('status', Status::Planned)['tasks']->pluck('id')->all();
    $doneIds = collect($columns)->firstWhere('status', Status::Done)['tasks']->pluck('id')->all();

    expect($planned)->toBe([$this->task->id])
        ->and($doneIds)->toBe([$done->id]);
});

it('renders a per-column search input keyed by status', function () {
    $html = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->html();

    expect($html)
        ->toContain('data-test="column-'.Status::Planned->value.'"')
        ->toContain('data-test="column-search-'.Status::Planned->value.'"')
        ->toContain('data-test="column-search-'.Status::Done->value.'"');
});

it('starts a column search collapsed, but expanded when it has an active term', function () {
    // KAN-282: the search is an icon by default and only expands to an input on
    // demand — except a column that already carries a search term renders open
    // so the active filter stays visible across re-renders.
    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC']);

    expect($component->html())->not->toContain('expanded: true');

    $component->set('columnSearch.'.Status::Planned->value, 'polish');

    expect($component->html())->toContain('expanded: true');
});

it('counts the active board filters for the filter badge', function () {
    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC']);

    expect($component->instance()->activeFilterCount())->toBe(0);

    $component->set('showArchived', true);
    expect($component->instance()->activeFilterCount())->toBe(1);

    $component->set('priorityFilter', Priority::Highest->value);
    expect($component->instance()->activeFilterCount())->toBe(2);
});

it('creates a root task from the create dialog', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'New Task')
        ->call('save');

    $task = $this->project->tasks()->where('title', 'New Task')->first();

    expect($task)->not->toBeNull()
        ->and($task->parent_id)->toBeNull();
});

it('requires a title to create a task', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('renders a typed task\'s type badge on its card', function () {
    $type = TaskType::factory()->for($this->project)->create(['name' => 'Bugfix']);
    $this->task->taskType()->associate($type)->save();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSee('Bugfix');
});

it('filters the board to a single task type', function () {
    $bug = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);
    $feature = TaskType::factory()->for($this->project)->create(['name' => 'Feature']);

    $bugTask = Task::factory()->for($this->project)->create(['title' => 'A bug']);
    $bugTask->taskType()->associate($bug)->save();
    $featureTask = Task::factory()->for($this->project)->create(['title' => 'A feature']);
    $featureTask->taskType()->associate($feature)->save();

    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('typeFilter', $bug->id);

    $ids = collect($component->instance()->columns())
        ->flatMap(static fn (array $column) => $column['tasks']->pluck('id'))
        ->all();

    // Only the matching type's task remains; the other type and the untyped
    // beforeEach task are filtered out.
    expect($ids)->toContain($bugTask->id)
        ->and($ids)->not->toContain($featureTask->id)
        ->and($ids)->not->toContain($this->task->id);
});

it('counts the type filter as an active filter', function () {
    $type = TaskType::factory()->for($this->project)->create();

    $count = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->set('typeFilter', $type->id)
        ->instance()->activeFilterCount();

    expect($count)->toBe(1);
});

it('shows the type filter only when the project has types', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertDontSeeHtml('data-test="type-filter"');

    TaskType::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSeeHtml('data-test="type-filter"');
});

it('keeps canceled tasks off the project board lanes', function () {
    $canceled = Task::factory()->for($this->project)->canceled()->create(['title' => 'Abandoned work']);

    $component = Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertDontSee('Abandoned work');

    $ids = collect($component->instance()->columns())
        ->flatMap(static fn (array $column) => $column['tasks']->pluck('id'))
        ->all();

    expect($ids)->not->toContain($canceled->id);
});
