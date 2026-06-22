<?php

use App\Livewire\CommandPalette;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    $this->project->members()->attach($this->user);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Deploy fix']);
});

it('finds a task by its title', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Deploy')
        ->assertSee('Deploy fix');
});

it('finds a project by its short name', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'ABC')
        ->assertSee('Acme Board');
});

it('matches titles case-insensitively regardless of query case', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'deploy') // stored as "Deploy fix"
        ->assertSee('Deploy fix')
        ->set('query', 'ACME') // stored as "Acme Board"
        ->assertSee('Acme Board');
});

it('finds a task by its tag', function () {
    $this->task->syncTags('urgent');

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'urgent')
        ->assertSee('Deploy fix');
});

it('does not attach progress to task or project results', function () {
    $project = app(GlobalSearch::class)->search($this->user, 'ABC')->firstWhere('type', 'project');
    $task = app(GlobalSearch::class)->search($this->user, 'Deploy')->firstWhere('type', 'task');

    expect($project->progress)->toBeNull()
        ->and($task->progress)->toBeNull();
});

it('pins a jump result for a typed reference', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', $this->task->reference)
        ->assertSee('Deploy fix')
        ->assertSee($this->task->reference);
});

it('finds a task by a compact reference without the separator', function () {
    $compact = strtolower(str_replace('-', '', $this->task->reference)); // "ABC-1" -> "abc1"

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', $compact)
        ->assertSee('Deploy fix')
        ->assertSee($this->task->reference);
});

it('pins a compact reference jump just like the dashed form', function () {
    $compact = str_replace('-', '', $this->task->reference); // "ABC1"

    $result = app(GlobalSearch::class)->search($this->user, $compact)->first();

    expect($result->reference)->toBe($this->task->reference)
        ->and($result->pinned)->toBeTrue();
});

it('surfaces every accessible task that carries a bare task number', function () {
    $other = Project::factory()->create(['short_name' => 'DEF']);
    $other->members()->attach($this->user);
    $otherTask = Task::factory()->for($other)->create(['title' => 'Other fix']);

    // Both first tasks share task_number 1.
    expect($this->task->task_number)->toBe($otherTask->task_number);

    $references = app(GlobalSearch::class)
        ->search($this->user, (string) $this->task->task_number)
        ->pluck('reference');

    expect($references)->toContain($this->task->reference)
        ->and($references)->toContain($otherTask->reference);
});

it('prioritizes and pins the current project task on a bare-number search', function () {
    $other = Project::factory()->create(['short_name' => 'DEF']);
    $other->members()->attach($this->user);
    $otherTask = Task::factory()->for($other)->create(['title' => 'Other fix']);

    $results = app(GlobalSearch::class)
        ->search($this->user, (string) $otherTask->task_number, contextShortName: 'DEF');

    expect($results->first()->reference)->toBe($otherTask->reference)
        ->and($results->first()->pinned)->toBeTrue();
});

it('does not surface a bare-number task from an inaccessible project', function () {
    $hidden = Project::factory()->create(['short_name' => 'XYZ']);
    $hiddenTask = Task::factory()->for($hidden)->create(['title' => 'Secret task']);

    $references = app(GlobalSearch::class)
        ->search($this->user, (string) $hiddenTask->task_number)
        ->pluck('reference');

    expect($references)->not->toContain($hiddenTask->reference);
});

describe('search edge cases', function () {
    it('ignores an unknown context project and still returns matches unpinned', function () {
        $results = app(GlobalSearch::class)
            ->search($this->user, (string) $this->task->task_number, contextShortName: 'NOPE');

        expect($results->pluck('reference'))->toContain($this->task->reference)
            ->and($results->firstWhere('reference', $this->task->reference)->pinned)->toBeFalse();
    });

    it('ignores an inaccessible context project without leaking its tasks', function () {
        $hidden = Project::factory()->create(['short_name' => 'XYZ']);
        $hiddenTask = Task::factory()->for($hidden)->create();

        $references = app(GlobalSearch::class)
            ->search($this->user, (string) $this->task->task_number, contextShortName: 'XYZ')
            ->pluck('reference');

        expect($references)->toContain($this->task->reference)
            ->and($references)->not->toContain($hiddenTask->reference);
    });

    it('does not treat a malformed or unresolvable compact reference as a jump', function (string $query) {
        expect(app(GlobalSearch::class)->search($this->user, $query)->where('pinned', true))->toBeEmpty();
    })->with([
        'well-formed but no such task number' => ['abc12'],
        'well-formed but no such project' => ['zzz1'],
        'trailing junk after the number' => ['abc1x'],
        'a lone dash with no number' => ['abc-'],
        'a single-letter prefix' => ['a1'],
    ]);

    it('returns nothing for a bare number with no matching task', function () {
        expect(app(GlobalSearch::class)->search($this->user, '9999'))->toBeEmpty();
    });

    it('does not jump to a compact reference the user cannot access', function () {
        $hidden = Project::factory()->create(['short_name' => 'XYZ']);
        Task::factory()->for($hidden)->create(['title' => 'Secret task']);

        Livewire::actingAs($this->user)
            ->test(CommandPalette::class)
            ->set('query', 'xyz1') // compact form of XYZ-1
            ->assertDontSee('Secret task');
    });
});

it('does not surface items from projects the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    Task::factory()->for($otherProject)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Secret')
        ->assertDontSee('Secret task');
});

it('does not jump to a reference the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    $otherTask = Task::factory()->for($otherProject)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', $otherTask->reference)
        ->assertDontSee('Secret task');
});

it('shows the quick actions immediately, before any query', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->assertSee('Dashboard')
        ->assertSee('Projects')
        ->assertSee('Board')
        ->assertSee('Notifications');
});

it('shows the New project action only to permitted users', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->assertDontSee('New project');

    $creator = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->assertSee('New project');
});

it('deep-links the New project action to the open create form', function () {
    $creator = User::factory()->canCreateProjects()->create();

    $action = Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->instance()
        ->actions()
        ->firstWhere('title', 'New project');

    expect($action->url)->toContain('create=1');
});

it('offers the New task action to project members', function () {
    $action = Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->instance()
        ->actions()
        ->firstWhere('title', 'New task');

    expect($action)->not->toBeNull()
        ->and($action->event)->toBe('open-create-task');
});

it('hides the New task action from users with no projects', function () {
    $stranger = User::factory()->create();

    $action = Livewire::actingAs($stranger)
        ->test(CommandPalette::class)
        ->instance()
        ->actions()
        ->firstWhere('title', 'New task');

    expect($action)->toBeNull();
});

it('opens the create dialog and closes the palette when New task is run', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'New')
        ->call('runAction', 'open-create-task')
        ->assertDispatched('open-create-task')
        ->assertDispatched('modal-close', name: 'command-palette')
        ->assertSet('query', '');
});

it('clears its query when closed', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Deploy')
        ->call('close')
        ->assertSet('query', '');
});

it('navigates to the selected entry', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->call('go', route('dashboard'))
        ->assertRedirect(route('dashboard'));
});
