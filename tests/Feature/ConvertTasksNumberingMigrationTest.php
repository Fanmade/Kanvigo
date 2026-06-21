<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The migration runs DDL, which cannot live inside RefreshDatabase's wrapping
// transaction. Rebuild the schema fresh around each test instead.
beforeEach(fn () => Artisan::call('migrate:fresh'));
afterEach(fn () => Artisan::call('migrate:fresh'));

/**
 * Recreate the `tasks`/`stories` schema as it was the moment before the
 * flat-numbering migration runs: per-story `story_id`/`task_number` (with the old
 * unique), `parent_id` already added, and no `project_id` yet.
 */
function rebuildLegacyTaskSchema(): void
{
    // task_user carries an FK to tasks, so it must go before the table is dropped
    // (Postgres enforces this). The test reseeds only what the migration needs.
    Schema::dropIfExists('task_user');
    Schema::dropIfExists('tasks');
    Schema::dropIfExists('stories');

    Schema::create('stories', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->unsignedInteger('story_number');
        $table->string('title');
        $table->text('description')->nullable();
        $table->unsignedTinyInteger('priority')->default(Priority::default()->value);
        $table->date('due_date')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();
    });

    Schema::create('tasks', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('story_id')->constrained()->cascadeOnDelete();
        $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
        $table->unsignedInteger('task_number');
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('status')->default(Status::Planned->value);
        $table->date('due_date')->nullable();
        $table->unsignedTinyInteger('priority')->default(Priority::default()->value);
        $table->double('position')->default(0);
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();

        $table->unique(['story_id', 'task_number']);
        $table->index(['story_id', 'status']);
    });
}

function loadConvertTasksMigration(): object
{
    return require database_path('migrations/2026_06_20_214028_convert_tasks_to_flat_project_numbering.php');
}

it('renumbers per project without colliding on the old per-story unique constraint', function () {
    rebuildLegacyTaskSchema();

    $project = Project::factory()->create(['short_name' => 'ABC']);
    $now = now();

    // Two stories in one project, with OVERLAPPING per-story task numbers. The
    // single task in story A has a lower id than story B's tasks, so the naive
    // (pre-fix) renumber would set story B's "1" to project number 2 — colliding
    // with story B's existing "2" under the (story_id, task_number) unique.
    $storyA = DB::table('stories')->insertGetId(storyRow($project->id, 1, $now));
    $storyB = DB::table('stories')->insertGetId(storyRow($project->id, 2, $now));

    DB::table('tasks')->insert(legacyTaskRow($storyA, 1, 'A1', $now));
    DB::table('tasks')->insert(legacyTaskRow($storyB, 1, 'B1', $now));
    DB::table('tasks')->insert(legacyTaskRow($storyB, 2, 'B2', $now));

    // Must not throw a unique violation.
    loadConvertTasksMigration()->up();

    $numbers = DB::table('tasks')->where('project_id', $project->id)->pluck('task_number')->sort()->values();

    expect($numbers->all())->toBe([1, 2, 3])                                  // flat, contiguous
        ->and($numbers->unique())->toHaveCount(3)                            // unique per project
        ->and(DB::table('tasks')->whereNull('project_id')->count())->toBe(0); // every task got a project
});

/**
 * @return array<string, mixed>
 */
function storyRow(int $projectId, int $number, mixed $now): array
{
    return [
        'project_id' => $projectId,
        'story_number' => $number,
        'title' => 'Story '.$number,
        'priority' => Priority::default()->value,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

/**
 * @return array<string, mixed>
 */
function legacyTaskRow(int $storyId, int $number, string $title, mixed $now): array
{
    return [
        'story_id' => $storyId,
        'parent_id' => null,
        'task_number' => $number,
        'title' => $title,
        'status' => Status::ToDo->value,
        'priority' => Priority::default()->value,
        'position' => $number,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
