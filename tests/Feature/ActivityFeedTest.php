<?php

use App\Livewire\Activity\ActivityFeed;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
});

it('collapses the activity feed by default', function () {
    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSet('collapsed', true);
});

it('exposes the activity count for the badge', function () {
    $count = Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->instance()->activityCount();

    // The task factory logs a single "created" activity.
    expect($count)->toBe(1);
});

it('persists the collapsed state as a user preference when toggled', function () {
    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->call('toggleCollapsed')
        ->assertSet('collapsed', false)
        ->call('toggleCollapsed')
        ->assertSet('collapsed', true);

    expect($this->member->fresh()->preference('activities_collapsed'))->toBeTrue();
});

it('restores the expanded state from the user preference on mount', function () {
    $this->member->setPreference('activities_collapsed', false);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSet('collapsed', false);
});

it('applies the collapsed preference across all subject types', function () {
    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->call('toggleCollapsed')
        ->assertSet('collapsed', false);

    Livewire::actingAs($this->member->fresh())
        ->test(ActivityFeed::class, ['subject' => $this->project])
        ->assertSet('collapsed', false);
});

it('renders a priority change without crashing on its numeric values', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('priority_changed', 'priority', '2', '4');

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertOk()
        ->assertSee('changed priority from Low to High');
});

it('shows which assignees were added and removed', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('assignee_changed', 'assignees', json_encode(['Carol']), json_encode(['Alice', 'Bob']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('assigned Alice and Bob, unassigned Carol');
});

it('shows only added assignees when none were removed', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('assignee_changed', 'assignees', null, json_encode(['Alice']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('assigned Alice')
        ->assertDontSee('unassigned');
});

it('localizes the assignee change in German', function () {
    app()->setLocale('de');
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('assignee_changed', 'assignees', json_encode(['Carol']), json_encode(['Alice', 'Bob']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('wies Alice und Bob zu und entfernte Carol aus den Zuständigen');
});

it('falls back to a generic line for legacy assignee entries without detail', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('assignee_changed', 'assignees');

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('updated the assignees');
});

it('shows which tags were added and removed', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('tags_changed', 'tags', json_encode(['stale']), json_encode(['urgent', 'bug']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('added the tags urgent and bug, removed stale');
});

it('describes an added dependency with its direction and reference', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('dependency_changed', 'dependencies', null, json_encode(['direction' => 'blocked_by', 'reference' => 'ABC-2']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('is now blocked by ABC-2');
});

it('describes a removed dependency', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('dependency_changed', 'dependencies', json_encode(['direction' => 'blocks', 'reference' => 'ABC-3']), null);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('no longer blocks ABC-3');
});

it('localizes a dependency change in German', function () {
    app()->setLocale('de');
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('dependency_changed', 'dependencies', null, json_encode(['direction' => 'blocked_by', 'reference' => 'ABC-2']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('wird jetzt von ABC-2 blockiert');
});

it('falls back to generic lines for legacy tag and dependency entries', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('tags_changed', 'tags');
    $this->task->recordActivity('dependency_changed', 'dependencies');

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('updated the tags')
        ->assertSee('updated the dependencies');
});

it('describes a cancellation with its reason and message', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('canceled', 'cancellation', null, json_encode(['reason' => 'duplicate', 'message' => 'Same as ABC-1']));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('canceled this as Duplicate — Same as ABC-1');
});

it('describes a cancellation without a message', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('canceled', 'cancellation', null, json_encode(['reason' => 'wont_fix', 'message' => null]));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('canceled this as Won\'t fix')
        ->assertDontSee('—');
});

it('describes reopening a task', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('reopened', 'cancellation', 'duplicate', null);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('reopened this');
});

it('localizes a cancellation in German', function () {
    app()->setLocale('de');
    $this->member->setPreference('activities_collapsed', false);
    $this->task->recordActivity('canceled', 'cancellation', null, json_encode(['reason' => 'deprecated', 'message' => null]));

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('hat dies abgebrochen (Veraltet)');
});

it('flags a token-driven action generically without leaking the token name', function () {
    $this->member->setPreference('activities_collapsed', false);
    $this->task->activities()->create([
        'user_id' => $this->member->id,
        'token_name' => 'Claude',
        'action' => 'status_changed',
        'field' => 'status',
        'old_value' => 'todo',
        'new_value' => 'done',
    ]);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertSee('via API token')
        ->assertDontSee('Claude'); // the private token name must not be shown
});

it('shows no token attribution for a web-session action', function () {
    $this->member->setPreference('activities_collapsed', false);

    // The factory-created task already logged a web-session "created" activity.
    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertDontSee('via token');
});

it('forbids non-members from viewing the feed', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->assertForbidden();
});

it('shows only the first page of activity and reveals older entries on demand', function () {
    // The task factory already logged one "created" activity; add a full page more.
    foreach (range(1, ActivityFeed::PER_PAGE) as $ignored) {
        $this->task->recordActivity('commented');
    }

    $component = Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task]);

    // Only the first page loads, even though more exist (bounded per-render load).
    expect($component->instance()->activities())->toHaveCount(ActivityFeed::PER_PAGE)
        ->and($component->instance()->hasMoreActivities())->toBeTrue();

    $component->call('showMore');

    // PER_PAGE "commented" + 1 "created" are now all visible.
    expect($component->instance()->activities())->toHaveCount(ActivityFeed::PER_PAGE + 1)
        ->and($component->instance()->hasMoreActivities())->toBeFalse();
});
