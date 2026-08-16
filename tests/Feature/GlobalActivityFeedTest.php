<?php

use App\Livewire\Activity\GlobalActivityFeed;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
});

it('lists activity from the projects the user can read', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Ship it']);

    Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->assertOk()
        ->assertSee($task->reference)
        ->assertSeeHtml('data-test="activity-row"');
});

it('hides activity from a project the user is not in', function () {
    $foreign = Project::factory()->create(['short_name' => 'XYZ']);
    $hidden = Task::factory()->for($foreign)->create(['title' => 'Secret work']);
    Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->assertDontSee($hidden->reference)
        ->assertDontSee('Secret work');
});

it('shows an empty state when nothing is visible', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(GlobalActivityFeed::class)
        ->assertSeeHtml('data-test="activity-empty"')
        ->assertDontSeeHtml('data-test="activity-row"');
});

it('pages with a cursor so a new entry cannot shift the next page', function () {
    Task::factory()->count(GlobalActivityFeed::PER_PAGE + 5)->for($this->project)->create();

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    $paginator = $component->instance()->activities();
    expect($paginator->hasMorePages())->toBeTrue()
        ->and($paginator->count())->toBe(GlobalActivityFeed::PER_PAGE);

    $firstPageIds = collect($paginator->items())->pluck('id');

    // Something new arrives at the head of the feed, then the reader pages on.
    Task::factory()->for($this->project)->create();

    $next = Livewire::actingAs($this->member)
        ->withQueryParams(['cursor' => $paginator->nextCursor()->encode()])
        ->test(GlobalActivityFeed::class)
        ->instance()
        ->activities();

    // Offset pagination would repeat rows here; a cursor is anchored to a row.
    expect(collect($next->items())->pluck('id')->intersect($firstPageIds))->toBeEmpty();
});

it('renders the list without a query per row', function () {
    $countQueries = function (int $tasks): int {
        Task::factory()->count($tasks)->for($this->project)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->member)
            ->test(GlobalActivityFeed::class)
            ->html();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $countQueries(2);
    $large = $countQueries(20);

    expect($large)->toBeLessThanOrEqual($small);
});

it('links a doc entry to its doc and a task entry to the log entry', function () {
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->published()->create();

    $html = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class)->html();

    expect($html)
        ->toContain(route('doc.show', ['short_name' => 'ABC', 'doc_number' => $doc->doc_number]))
        ->toContain(route('task.show', ['short_name' => 'ABC', 'task_number' => $task->task_number]));
});

it('groups the page by day', function () {
    Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->assertSeeHtml('data-test="activity-day"')
        ->assertSee(__('Today'));
});

it('is reachable from the sidebar', function () {
    $this->actingAs($this->member)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee(route('activity.index'));
});
