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
    $project->members()->attach($member);
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

it('reuses the same tag across tasks', function () {
    $task = Task::factory()->create();
    $other = Task::factory()->create();

    $task->syncTags('shared');
    $other->syncTags('shared');

    expect(Tag::where('name', 'shared')->count())->toBe(1)
        ->and($task->tags()->count())->toBe(1)
        ->and($other->tags()->count())->toBe(1);
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
    $tag = Tag::factory()->create(['name' => 'stale']);
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

it('suggests the most-used tags and excludes applied ones', function () {
    [$member, $task] = memberAndTask();

    $popular = Tag::factory()->create(['name' => 'popular']);
    Tag::factory()->create(['name' => 'rare']);
    $applied = Tag::factory()->create(['name' => 'applied']);

    // Drive up "popular" usage across other tasks.
    Task::factory()->count(3)->create()->each(fn (Task $other) => $other->tags()->attach($popular));
    $task->tags()->attach($applied);

    $names = collect(taskView($member, $task)->instance()->tagSuggestions)->pluck('name')->all();

    expect($names)->not->toContain('applied')
        ->and(array_search('popular', $names, true))->toBeLessThan(array_search('rare', $names, true));
});

it('adds a tag to a subtask too', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $project->members()->attach($member);
    $parent = Task::factory()->for($project)->create();
    $subtask = Task::factory()->for($project)->childOf($parent)->create();

    Livewire::actingAs($member)
        ->test(TaskView::class, ['short_name' => 'XYZ', 'task_number' => $subtask->task_number])
        ->call('addTag', 'epic');

    expect($subtask->fresh()->tags->pluck('name')->all())->toBe(['epic']);
});
