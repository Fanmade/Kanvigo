<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
});

/**
 * The number of logged queries against the tasks table since enabling the log.
 */
function taskQueryCount(): int
{
    return collect(DB::getQueryLog())
        ->filter(static fn (array $q): bool => str_contains($q['query'], 'from "tasks"'))
        ->count();
}

describe('the freshness token', function () {
    it('starts at zero for an untouched project and increments on touch', function () {
        expect(BoardCache::version(999_001))->toBe(0);

        BoardCache::touch(999_001);

        expect(BoardCache::version(999_001))->toBe(1);
    });

    it('changes the composite token when any member project is touched', function () {
        $before = BoardCache::versionFor([999_001, 999_002]);

        BoardCache::touch(999_002);

        expect(BoardCache::versionFor([999_001, 999_002]))->not->toBe($before);
    });
});

it('serves the project board from cache on an idle re-render', function () {
    // First render builds and caches the board.
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC']);

    DB::enableQueryLog();
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC']);
    $count = taskQueryCount();
    DB::disableQueryLog();

    expect($count)->toBe(0);
});

it('serves the global board from cache on an idle re-render', function () {
    Livewire::actingAs($this->user)->test(Board::class);

    DB::enableQueryLog();
    Livewire::actingAs($this->user)->test(Board::class);
    $count = taskQueryCount();
    DB::disableQueryLog();

    expect($count)->toBe(0);
});

it('rebuilds the board after a task row change invalidates the cache', function () {
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC']);
    $before = BoardCache::version($this->project->id);

    /** @noinspection LaravelEloquentGuardedAttributeAssignmentInspection */
    $this->task->update(['status' => Status::Done]); // Task saved → touch

    expect(BoardCache::version($this->project->id))->toBeGreaterThan($before);

    DB::enableQueryLog();
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC']);
    $count = taskQueryCount();
    DB::disableQueryLog();

    expect($count)->toBeGreaterThan(0); // cache miss → fresh scan
});

it('invalidates on a task pivot change recorded as activity', function () {
    $before = BoardCache::version($this->project->id);

    seedActivity($this->task, 'tags_changed', 'tags', null, '["urgent"]');

    expect(BoardCache::version($this->project->id))->toBeGreaterThan($before);
});

it('does not invalidate on a comment activity', function () {
    $before = BoardCache::version($this->project->id);

    seedActivity($this->task, 'commented');

    expect(BoardCache::version($this->project->id))->toBe($before);
});
