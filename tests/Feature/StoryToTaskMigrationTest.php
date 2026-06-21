<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The migration itself runs DDL (dropping the stories table), which cannot live
// inside RefreshDatabase's wrapping transaction. Rebuild the schema fresh around
// the test instead so the migration executes exactly as it would in production.
beforeEach(fn () => Artisan::call('migrate:fresh'));
afterEach(fn () => Artisan::call('migrate:fresh'));

const STORY_TYPE = 'App\Models\Story';
const TASK_TYPE = 'App\Models\Task';

/**
 * Re-create the pre-migration schema fragments that the migration drops, so a
 * realistic story+tasks fixture can be built and run through the migration.
 */
function rebuildStorySchema(): void
{
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

    Schema::create('story_user', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('story_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::table('tasks', static function (Blueprint $table): void {
        $table->foreignId('story_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        $table->index(['story_id', 'status']);
    });
}

/**
 * Load the anonymous migration instance from its file.
 */
function loadStoryMigration(): object
{
    return require database_path('migrations/2026_06_20_233915_migrate_stories_into_root_tasks.php');
}

it('migrates several stories in one project into separate root trees, renumbering cleanly', function () {
    rebuildStorySchema();

    $project = Project::factory()->create(['short_name' => 'ABC']);
    $now = now();

    // Two stories in one project. Their tasks are already flat-numbered per project
    // (1..4) — the state after the convert-tasks migration — so this also guards the
    // root-insertion + offset renumber against a multi-story collision.
    $storyA = DB::table('stories')->insertGetId(['project_id' => $project->id, 'story_number' => 1, 'title' => 'Story A', 'priority' => Priority::default()->value, 'created_at' => $now, 'updated_at' => $now]);
    $storyB = DB::table('stories')->insertGetId(['project_id' => $project->id, 'story_number' => 2, 'title' => 'Story B', 'priority' => Priority::default()->value, 'created_at' => $now, 'updated_at' => $now]);

    $a1 = DB::table('tasks')->insertGetId(taskRow($project->id, $storyA, 1, 'A1', Status::ToDo, $now));
    $a2 = DB::table('tasks')->insertGetId(taskRow($project->id, $storyA, 2, 'A2', Status::Done, $now));
    $b1 = DB::table('tasks')->insertGetId(taskRow($project->id, $storyB, 3, 'B1', Status::ToDo, $now));
    $b2 = DB::table('tasks')->insertGetId(taskRow($project->id, $storyB, 4, 'B2', Status::ToDo, $now));

    loadStoryMigration()->up();

    $rootA = DB::table('tasks')->where('project_id', $project->id)->whereNull('parent_id')->where('title', 'Story A')->first();
    $rootB = DB::table('tasks')->where('project_id', $project->id)->whereNull('parent_id')->where('title', 'Story B')->first();

    expect($rootA)->not->toBeNull()->and($rootB)->not->toBeNull();

    // Each story's tasks hang off the correct root — no cross-wiring.
    expect(DB::table('tasks')->where('id', $a1)->value('parent_id'))->toBe($rootA->id)
        ->and(DB::table('tasks')->where('id', $a2)->value('parent_id'))->toBe($rootA->id)
        ->and(DB::table('tasks')->where('id', $b1)->value('parent_id'))->toBe($rootB->id)
        ->and(DB::table('tasks')->where('id', $b2)->value('parent_id'))->toBe($rootB->id);

    // Six tasks now (4 originals + 2 roots), flat-numbered 1..6, unique per project.
    $numbers = DB::table('tasks')->where('project_id', $project->id)->pluck('task_number');
    expect($numbers)->toHaveCount(6)
        ->and($numbers->unique())->toHaveCount(6)
        ->and($numbers->min())->toBe(1)
        ->and($numbers->max())->toBe(6);
});

it('migrates a story and its tasks into a correct task tree with relations intact', function () {
    rebuildStorySchema();

    $project = Project::factory()->create(['short_name' => 'ABC']);
    $member = User::factory()->create();
    $project->members()->attach($member);
    $now = now();

    $storyId = DB::table('stories')->insertGetId([
        'project_id' => $project->id,
        'story_number' => 1,
        'title' => 'Checkout revamp',
        'description' => 'Rework the checkout flow',
        'priority' => Priority::High->value,
        'due_date' => null,
        'archived_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Two tasks directly under the story, one of them already done.
    $taskDone = DB::table('tasks')->insertGetId(taskRow($project->id, $storyId, 1, 'Wire the form', Status::Done, $now));
    $taskOpen = DB::table('tasks')->insertGetId(taskRow($project->id, $storyId, 2, 'Validate input', Status::ToDo, $now));

    // A blocking task (separate root) the story depends on, plus story-side relations.
    $blockerStory = DB::table('stories')->insertGetId([
        'project_id' => $project->id, 'story_number' => 2, 'title' => 'Payments', 'description' => null,
        'priority' => Priority::default()->value, 'due_date' => null, 'archived_at' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('dependencies')->insert([
        'dependent_type' => STORY_TYPE, 'dependent_id' => $storyId,
        'blocker_type' => STORY_TYPE, 'blocker_id' => $blockerStory,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $tagId = DB::table('tags')->insertGetId(['name' => 'frontend', 'color' => 'sky', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('taggables')->insert(['tag_id' => $tagId, 'taggable_type' => STORY_TYPE, 'taggable_id' => $storyId]);

    DB::table('comments')->insert([
        'user_id' => $member->id, 'commentable_type' => STORY_TYPE, 'commentable_id' => $storyId,
        'body' => 'Looks good', 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('activities')->insert([
        'user_id' => $member->id, 'subject_type' => STORY_TYPE, 'subject_id' => $storyId,
        'action' => 'created', 'created_at' => $now,
    ]);

    DB::table('subscriptions')->insert([
        'user_id' => $member->id, 'subscribable_type' => STORY_TYPE, 'subscribable_id' => $storyId,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('story_user')->insert([
        'story_id' => $storyId, 'user_id' => $member->id, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // Run the migration.
    loadStoryMigration()->up();

    // The story is now a root task (parent_id null) in its project, carrying its title/priority.
    $root = DB::table('tasks')->where('project_id', $project->id)->whereNull('parent_id')
        ->where('title', 'Checkout revamp')->first();

    expect($root)->not->toBeNull()
        ->and($root->priority)->toBe(Priority::High->value)
        // It had a done child and an open child, so it lands "In progress".
        ->and($root->status)->toBe(Status::InProgress->value);

    // The story's tasks are now its children.
    expect(DB::table('tasks')->where('id', $taskDone)->value('parent_id'))->toBe($root->id)
        ->and(DB::table('tasks')->where('id', $taskOpen)->value('parent_id'))->toBe($root->id);

    $blockerRoot = DB::table('tasks')->where('project_id', $project->id)->whereNull('parent_id')
        ->where('title', 'Payments')->value('id');

    // Every polymorphic relation now points at the root task.
    expect(DB::table('dependencies')->where('dependent_type', TASK_TYPE)->where('dependent_id', $root->id)
        ->where('blocker_type', TASK_TYPE)->where('blocker_id', $blockerRoot)->exists())->toBeTrue()
        ->and(DB::table('taggables')->where('taggable_type', TASK_TYPE)->where('taggable_id', $root->id)->where('tag_id', $tagId)->exists())->toBeTrue()
        ->and(DB::table('comments')->where('commentable_type', TASK_TYPE)->where('commentable_id', $root->id)->exists())->toBeTrue()
        ->and(DB::table('activities')->where('subject_type', TASK_TYPE)->where('subject_id', $root->id)->exists())->toBeTrue()
        ->and(DB::table('subscriptions')->where('subscribable_type', TASK_TYPE)->where('subscribable_id', $root->id)->exists())->toBeTrue();

    // No story-side rows survive for the migrated subject.
    expect(DB::table('dependencies')->where('dependent_type', STORY_TYPE)->exists())->toBeFalse()
        ->and(DB::table('taggables')->where('taggable_type', STORY_TYPE)->exists())->toBeFalse();

    // The story's assignee moved to the root task.
    expect(DB::table('task_user')->where('task_id', $root->id)->where('user_id', $member->id)->exists())->toBeTrue();

    // Numbering is flat and unique per project; the tables are gone.
    expect(DB::table('tasks')->where('project_id', $project->id)->distinct()->count('task_number'))
        ->toBe(DB::table('tasks')->where('project_id', $project->id)->count())
        ->and(Schema::hasTable('stories'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'story_id'))->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function taskRow(int $projectId, int $storyId, int $number, string $title, Status $status, mixed $now): array
{
    return [
        'story_id' => $storyId,
        'project_id' => $projectId,
        'parent_id' => null,
        'task_number' => $number,
        'title' => $title,
        'description' => null,
        'status' => $status->value,
        'priority' => Priority::default()->value,
        'due_date' => null,
        'position' => $number,
        'archived_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
