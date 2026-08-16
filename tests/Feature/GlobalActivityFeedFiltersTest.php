<?php

use App\Audit\Sinks\ActivityLogSink;
use App\Enums\Priority;
use App\Livewire\Activity\GlobalActivityFeed;
use App\Livewire\Comments\CommentList;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create(['name' => 'Reader']);
    $this->other = User::factory()->create(['name' => 'Writer']);
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->member->id, $this->other->id]);
});

/**
 * Record a comment on the given task as the given user, through the component
 * the application uses — so the activity is written exactly as in production.
 */
function commentAs(User $user, Task $task, string $body): void
{
    Livewire::actingAs($user)
        ->test(CommentList::class, ['commentable' => $task])
        ->set('body', '<p>'.$body.'</p>')
        ->call('addComment')
        ->assertHasNoErrors();
}

it('leaves the reader own activity out by default and includes it on request', function () {
    $task = Task::factory()->for($this->project)->create();
    commentAs($this->member, $task, 'Mine');
    commentAs($this->other, $task, 'Theirs');

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    $actorIds = fn () => collect($component->instance()->activities()->items())
        ->pluck('user_id')
        ->unique();

    expect($actorIds())->not->toContain($this->member->id)
        ->and($actorIds())->toContain($this->other->id);

    $component->set('mine', true);

    expect($actorIds())->toContain($this->member->id);
});

it('filters by person', function () {
    $task = Task::factory()->for($this->project)->create();
    $third = User::factory()->create(['name' => 'Third']);
    joinProject($this->project, $third);

    commentAs($this->other, $task, 'From the writer');
    commentAs($third, $task, 'From the third');

    $items = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->set('actor', $this->other->public_id)
        ->instance()
        ->activities()
        ->items();

    expect(collect($items)->pluck('user_id')->unique()->all())->toBe([$this->other->id]);
});

it('filters by project', function () {
    $second = Project::factory()->create(['short_name' => 'XYZ']);
    joinProject($second, [$this->member->id, $this->other->id]);

    commentAs($this->other, Task::factory()->for($this->project)->create(), 'Here');
    commentAs($this->other, Task::factory()->for($second)->create(), 'There');

    $items = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->set('project', 'XYZ')
        ->instance()
        ->activities()
        ->items();

    expect(collect($items)->pluck('project_id')->unique()->all())->toBe([$second->id]);
});

it('filters by activity type', function () {
    $task = Task::factory()->for($this->project)->create();

    $this->actingAs($this->other);
    $task->update(['priority' => Priority::Highest]);
    commentAs($this->other, $task, 'A comment');

    $items = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->set('category', 'comments')
        ->instance()
        ->activities()
        ->items();

    expect(collect($items)->pluck('action')->unique()->all())->toBe(['commented']);
});

it('filters by period', function () {
    $task = Task::factory()->for($this->project)->create();
    commentAs($this->other, $task, 'Recent');

    Activity::query()->where('action', 'created')->update(['created_at' => Carbon::now()->subMonths(2)]);

    $items = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->set('range', 'today')
        ->instance()
        ->activities()
        ->items();

    expect(collect($items)->pluck('action')->unique()->all())->toBe(['commented']);
});

it('keeps the filters in the query string so a filtered feed is linkable', function () {
    Livewire::actingAs($this->member)
        ->withQueryParams(['category' => 'comments', 'project' => 'ABC', 'mine' => true])
        ->test(GlobalActivityFeed::class)
        ->assertSet('category', 'comments')
        ->assertSet('project', 'ABC')
        ->assertSet('mine', true);
});

it('resets the cursor when a filter changes', function () {
    Task::factory()->count(GlobalActivityFeed::PER_PAGE + 5)->for($this->project)->create();

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);
    $cursor = $component->instance()->activities()->nextCursor()->encode();

    // Page on, then narrow the feed: the old cursor points at a row that the new
    // result set may not contain at all.
    $component->call('nextPage')
        ->set('category', 'comments')
        ->assertSet('paginators.cursor', null);

    expect($cursor)->not->toBeEmpty();
});

it('offers only people who share a project with the reader', function () {
    $stranger = User::factory()->create(['name' => 'Stranger']);

    $actors = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->instance()
        ->actors()
        ->pluck('id');

    expect($actors)->toContain($this->other->id)
        ->and($actors)->not->toContain($stranger->id);
});

it('keeps a hand-edited filter from widening what is visible', function () {
    $foreign = Project::factory()->create(['short_name' => 'XYZ']);
    $outsider = User::factory()->create();
    joinProject($foreign, $outsider);
    commentAs($outsider, Task::factory()->for($foreign)->create(), 'Not for you');

    $items = Livewire::actingAs($this->member)
        ->withQueryParams(['project' => 'XYZ', 'actor' => $outsider->public_id])
        ->test(GlobalActivityFeed::class)
        ->instance()
        ->activities()
        ->items();

    expect($items)->toBeEmpty();
});

it('covers every feed action with exactly one filter category', function () {
    $categorized = collect(GlobalActivityFeed::ACTION_CATEGORIES)->flatten();

    expect($categorized->duplicates())->toBeEmpty()
        ->and($categorized->sort()->values()->all())
        ->toEqualCanonicalizing(ActivityLogSink::FEED_ACTIONS);
});
