<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The migration runs DDL, which cannot live inside RefreshDatabase's wrapping
// transaction. Rebuild the schema fresh around each test instead.
beforeEach(fn () => Artisan::call('migrate:fresh'));
afterEach(fn () => Artisan::call('migrate:fresh'));

/**
 * Recreate the project-scoped `tags`/`taggables` schema as it was before this
 * migration: a per-project exact unique on name (which still lets case variants
 * like "Bug"/"bug" coexist) and no case-insensitive index yet.
 */
function rebuildExactUniqueTagsSchema(): void
{
    Schema::dropIfExists('taggables');
    Schema::dropIfExists('tags');

    Schema::create('tags', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('color')->default('zinc');
        $table->timestamps();
        $table->unique(['project_id', 'name']);
    });

    Schema::create('taggables', static function (Blueprint $table): void {
        $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
        $table->morphs('taggable');
        $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
    });
}

function loadCaseInsensitiveTagsMigration(): object
{
    return require database_path('migrations/2026_06_23_114628_enforce_case_insensitive_tag_names.php');
}

function insertProjectTag(int $projectId, string $name, string $color = 'zinc'): int
{
    return DB::table('tags')->insertGetId([
        'project_id' => $projectId,
        'name' => $name,
        'color' => $color,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function attachTagToTask(int $tagId, Task $task): void
{
    DB::table('taggables')->insert([
        'tag_id' => $tagId,
        'taggable_id' => $task->id,
        'taggable_type' => $task->getMorphClass(),
    ]);
}

it('merges case-variant duplicates into the earliest tag and repoints the pivot', function () {
    rebuildExactUniqueTagsSchema();

    $project = Project::factory()->create();
    $taskA = Task::factory()->for($project)->create();
    $taskB = Task::factory()->for($project)->create();

    $bug = insertProjectTag($project->id, 'Bug', 'sky');   // earliest — the keeper
    $bugLower = insertProjectTag($project->id, 'bug', 'rose');
    attachTagToTask($bug, $taskA);
    attachTagToTask($bugLower, $taskB);

    loadCaseInsensitiveTagsMigration()->up();

    $remaining = DB::table('tags')->where('project_id', $project->id)->get();

    expect($remaining)->toHaveCount(1)
        ->and((int) $remaining->first()->id)->toBe($bug)        // earliest id kept
        ->and($remaining->first()->name)->toBe('Bug')          // its casing preserved
        // Both tasks now reference the surviving tag.
        ->and((int) DB::table('taggables')->where('taggable_id', $taskB->id)->value('tag_id'))->toBe($bug)
        ->and(DB::table('taggables')->where('tag_id', $bug)->count())->toBe(2);
});

it('deduplicates the pivot when one task carried both casings', function () {
    rebuildExactUniqueTagsSchema();

    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    $bug = insertProjectTag($project->id, 'Bug');
    $bugLower = insertProjectTag($project->id, 'bug');
    attachTagToTask($bug, $task);
    attachTagToTask($bugLower, $task); // same task, both casings

    loadCaseInsensitiveTagsMigration()->up();

    expect(DB::table('tags')->where('project_id', $project->id)->count())->toBe(1)
        ->and(DB::table('taggables')->where('taggable_id', $task->id)->count())->toBe(1);
});

it('leaves a case variant in a different project alone', function () {
    rebuildExactUniqueTagsSchema();

    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    insertProjectTag($projectA->id, 'Bug');
    insertProjectTag($projectB->id, 'bug');

    loadCaseInsensitiveTagsMigration()->up();

    expect(DB::table('tags')->count())->toBe(2);
});

it('rejects a case-variant duplicate within a project after the migration', function () {
    rebuildExactUniqueTagsSchema();

    $project = Project::factory()->create();
    insertProjectTag($project->id, 'Bug');

    loadCaseInsensitiveTagsMigration()->up();

    expect(fn () => insertProjectTag($project->id, 'BUG'))
        ->toThrow(UniqueConstraintViolationException::class);
});
