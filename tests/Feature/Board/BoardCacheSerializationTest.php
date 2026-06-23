<?php

use App\Enums\Status;
use App\Livewire\Board;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * These tests force the `database` cache store so the board's cached value is
 * genuinely serialized and unserialized — unlike the default `array` store,
 * which keeps live objects in memory and never exercises serialization. That
 * gap let a regression ship where the board cached a hydrated task graph the
 * `serializable_classes` allow-list did not cover, so every cache read came
 * back as `__PHP_Incomplete_Class` and the board 500'd in production.
 */
beforeEach(function () {
    config()->set('cache.default', 'database');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);

    // A parent + child so the cached graph carries the adjacency-list collection
    // and the `ancestors` relation; a tag (MorphPivot) and an assignee (Pivot)
    // so every class in the serialized graph is exercised.
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $child = Task::factory()->for($this->project)->childOf($parent)->status(Status::ToDo)->create();
    $child->assignees()->attach($this->user);
    $child->tags()->attach(Tag::factory()->create());
});

it('round-trips the project board graph through the serialized cache store', function () {
    // First render builds and caches the board (a serialize + DB write).
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertOk();

    // Second render reads the value back (unserialize). With a class missing from
    // the allow-list this returns __PHP_Incomplete_Class and tasks(): Collection
    // throws a TypeError, failing the render.
    Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertOk();
});

it('round-trips the global board graph through the serialized cache store', function () {
    Livewire::actingAs($this->user)->test(Board::class)->assertOk();

    Livewire::actingAs($this->user)->test(Board::class)->assertOk();
});

it('revives the cached tasks as a usable Eloquent collection', function () {
    $component = Livewire::actingAs($this->user)->test(ProjectBoard::class, ['short_name' => 'ABC']);

    // Read straight from the cache store to assert the revived shape, rather than
    // trusting the in-memory value from the building request.
    $key = 'board:proj:'.$this->project->id.':tasks:v'.BoardCache::version($this->project->id);
    $tasks = Cache::store('database')->get($key);

    expect($tasks)->toBeInstanceOf(Collection::class)
        ->and($tasks->first())->toBeInstanceOf(Task::class)
        ->and($tasks->first()->relationLoaded('assignees'))->toBeTrue();

    $component->assertOk();
});
