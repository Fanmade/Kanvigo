<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A project member viewing one of their tasks, ready for tag management.
 *
 * @return array{0: User, 1: Task}
 */
function memberAndTask(): array
{
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();

    return [$member, $task];
}

/**
 * A Livewire TaskView test instance for the given task, acting as the member.
 */
function taskView(User $member, Task $task): Testable
{
    return Livewire::actingAs($member)->test(TaskView::class, [
        'short_name' => $task->project->short_name,
        'task_number' => $task->task_number,
    ]);
}

it('attaches comma-separated tags, trimming and de-duplicating', function () {
    $task = Task::factory()->create();

    $task->syncTags('bug,  urgent , Bug');

    expect($task->tags()->count())->toBe(2)
        ->and(Tag::count())->toBe(2);
});

it('reuses the same tag across tasks in the same project', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $other = Task::factory()->for($project)->create();

    $task->syncTags('shared');
    $other->syncTags('shared');

    expect(Tag::where('name', 'shared')->count())->toBe(1)
        ->and($task->tags()->count())->toBe(1)
        ->and($other->tags()->count())->toBe(1);
});

it('scopes tags per project, so the same name in two projects is two distinct tags', function () {
    $taskA = Task::factory()->create(); // its own project
    $taskB = Task::factory()->create(); // a different project

    $taskA->syncTags('shared');
    $taskB->syncTags('shared');

    $tagA = $taskA->tags()->sole();
    $tagB = $taskB->tags()->sole();

    expect(Tag::where('name', 'shared')->count())->toBe(2)
        ->and($tagA->is($tagB))->toBeFalse()
        ->and($tagA->project_id)->toBe($taskA->project_id)
        ->and($tagB->project_id)->toBe($taskB->project_id);
});

it('resolves tags case-insensitively within a project, keeping the first casing', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $other = Task::factory()->for($project)->create();

    $task->syncTags('Bug');
    $other->syncTags('bug');

    expect(Tag::where('project_id', $project->id)->count())->toBe(1)
        ->and(Tag::where('project_id', $project->id)->sole()->name)->toBe('Bug')
        ->and($task->tags()->sole()->is($other->tags()->sole()))->toBeTrue();
});

it('treats a case variant as the same tag when added through the rail', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)
        ->call('addTag', 'Bug')
        ->call('addTag', 'bug');

    expect($task->fresh()->tags()->count())->toBe(1)
        ->and(Tag::where('project_id', $task->project_id)->count())->toBe(1);
});

it('detaches tags removed from the list', function () {
    $task = Task::factory()->create();

    $task->syncTags('a, b, c');
    $task->syncTags('a');

    expect($task->tags()->pluck('name')->all())->toBe(['a']);
});

it('assigns a deterministic palette color to newly created tags', function () {
    $task = Task::factory()->create();

    $task->syncTags('bug');

    $tag = Tag::where('name', 'bug')->sole();

    expect($tag->color)->toBe(Tag::colorForName('bug'))
        ->and(Tag::PALETTE)->toContain($tag->color);
});

it('maps the same name to the same color regardless of case', function () {
    expect(Tag::colorForName('Bug'))->toBe(Tag::colorForName('bug'));
});

it('keeps an explicitly provided color instead of auto-assigning', function () {
    $tag = Tag::create(['name' => 'special', 'color' => 'teal']);

    expect($tag->fresh()->color)->toBe('teal');
});

it('renders a tag as a badge with a dot in its color', function () {
    $tag = Tag::factory()->color('sky')->create(['name' => 'frontend']);

    $this->blade('<x-tag-badge :tag="$tag" />', ['tag' => $tag])
        ->assertSee('frontend')
        ->assertSee('bg-sky-500', false);
});

it('adds a tag to a task and logs the change', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)->call('addTag', 'urgent');

    $activity = $task->activities()->where('action', 'tags_changed')->first();

    expect($task->fresh()->tags->pluck('name')->all())->toBe(['urgent'])
        ->and($task->activities()->where('action', 'tags_changed')->count())->toBe(1)
        ->and(json_decode((string) $activity->new_value, true))->toBe(['urgent'])
        ->and($activity->old_value)->toBeNull();
});

it('does not duplicate an already-applied tag', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)
        ->call('addTag', 'urgent')
        ->call('addTag', 'urgent');

    expect($task->fresh()->tags()->count())->toBe(1)
        ->and($task->activities()->where('action', 'tags_changed')->count())->toBe(1);
});

it('removes a tag from a task and logs the change', function () {
    [$member, $task] = memberAndTask();
    $tag = Tag::factory()->for($task->project)->create(['name' => 'stale']);
    $task->tags()->attach($tag);

    taskView($member, $task)->call('removeTag', $tag->id);

    $activity = $task->activities()->where('action', 'tags_changed')->first();

    expect($task->fresh()->tags()->count())->toBe(0)
        ->and($task->activities()->where('action', 'tags_changed')->count())->toBe(1)
        ->and(json_decode((string) $activity->old_value, true))->toBe(['stale'])
        ->and($activity->new_value)->toBeNull();
});

it('creates a tag with the chosen color through the modal and applies it', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)
        ->set('newTagName', 'design')
        ->set('newTagColor', 'violet')
        ->call('createTag')
        ->assertSet('showTagModal', false);

    $tag = Tag::where('name', 'design')->sole();

    expect($tag->color)->toBe('violet')
        ->and($task->fresh()->tags->pluck('name')->all())->toBe(['design']);
});

it('rejects a create-tag color outside the palette', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)
        ->set('newTagName', 'design')
        ->set('newTagColor', 'chartreuse')
        ->call('createTag')
        ->assertHasErrors('newTagColor');

    expect(Tag::where('name', 'design')->exists())->toBeFalse();
});

it('prefills the create-tag modal from the typed text', function () {
    [$member, $task] = memberAndTask();

    taskView($member, $task)
        ->call('openTagModal', 'Frontend')
        ->assertSet('showTagModal', true)
        ->assertSet('newTagName', 'Frontend')
        ->assertSet('newTagColor', Tag::colorForName('Frontend'));
});

it('suggests the most-used tags in the project and excludes applied ones', function () {
    [$member, $task] = memberAndTask();
    $project = $task->project;

    $popular = Tag::factory()->for($project)->create(['name' => 'popular']);
    Tag::factory()->for($project)->create(['name' => 'rare']);
    $applied = Tag::factory()->for($project)->create(['name' => 'applied']);

    // Drive up "popular" usage across other tasks in the same project.
    Task::factory()->count(3)->for($project)->create()->each(fn (Task $other) => $other->tags()->attach($popular));
    $task->tags()->attach($applied);

    $names = collect(taskView($member, $task)->instance()->tagSuggestions)->pluck('name')->all();

    expect($names)->not->toContain('applied')
        ->and(array_search('popular', $names, true))->toBeLessThan(array_search('rare', $names, true));
});

it('does not suggest tags that belong to other projects', function () {
    [$member, $task] = memberAndTask();

    // A tag with the same usage but in a different project must not leak in.
    Tag::factory()->create(['name' => 'foreign']);

    $names = collect(taskView($member, $task)->instance()->tagSuggestions)->pluck('name')->all();

    expect($names)->not->toContain('foreign');
});

it('adds a tag to a subtask too', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    joinProject($project, $member);
    $parent = Task::factory()->for($project)->create();
    $subtask = Task::factory()->for($project)->childOf($parent)->create();

    Livewire::actingAs($member)
        ->test(TaskView::class, ['short_name' => 'XYZ', 'task_number' => $subtask->task_number])
        ->call('addTag', 'epic');

    expect($subtask->fresh()->tags->pluck('name')->all())->toBe(['epic']);
});
