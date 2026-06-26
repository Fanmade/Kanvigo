<?php

use App\Enums\RelationshipType;
use App\Enums\Status;
use App\Models\Dependency;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a task in a fresh project with the given status.
 */
function makeTask(Status $status = Status::Planned): Task
{
    return Task::factory()->for(Project::factory())->status($status)->create();
}

test('a task lists its blockers and the tasks it blocks', function () {
    $task = makeTask();
    $blocker = makeTask();

    $task->addBlocker($blocker);

    expect($task->blockers()->pluck('id'))->toContain($blocker->id)
        ->and($blocker->blocking()->pluck('id'))->toContain($task->id);
});

test('a non-blocking relationship never blocks the task', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();
    $other = Task::factory()->for($project)->status(Status::ToDo)->create();

    // "duplicates" is informational — the unfinished related task must not block.
    $task->addRelationship($other, RelationshipType::Duplicates, asSubject: true);

    expect($task->fresh()->isBlocked())->toBeFalse()
        ->and(Dependency::where('type', 'duplicates')->count())->toBe(1);
});

test('a symmetric relates link is stored once regardless of which side adds it', function () {
    $project = Project::factory()->create();
    $a = Task::factory()->for($project)->create();
    $b = Task::factory()->for($project)->create();

    $a->addRelationship($b, RelationshipType::Relates, asSubject: true);
    $b->addRelationship($a, RelationshipType::Relates, asSubject: true);

    expect(Dependency::where('type', 'relates')->count())->toBe(1)
        ->and($a->fresh()->relationshipReferences()['relates'])->toBe([$b->reference])
        ->and($b->fresh()->relationshipReferences()['relates'])->toBe([$a->reference]);
});

test('relationshipReferences groups the related tasks by keyword', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $dup = Task::factory()->for($project)->create();
    $blocker = Task::factory()->for($project)->status(Status::ToDo)->create();

    $task->addRelationship($dup, RelationshipType::Duplicates, asSubject: true);
    $task->addBlocker($blocker);

    $refs = $task->fresh()->relationshipReferences();

    expect($refs['duplicates'])->toBe([$dup->reference])
        ->and($refs['blocked_by'])->toBe([$blocker->reference])
        ->and($refs['blocks'])->toBe([])
        ->and($refs['relates'])->toBe([]);
});

test('dependencies work between tasks regardless of nesting', function () {
    $project = Project::factory()->create();
    $parent = Task::factory()->for($project)->create();
    $child = Task::factory()->for($project)->childOf($parent)->create();

    $blocker = makeTask();
    $parent->addBlocker($blocker);
    $child->addBlocker($parent);

    expect($parent->fresh()->blockers()->first())->toBeInstanceOf(Task::class)
        ->and($child->fresh()->blockers()->first())->toBeInstanceOf(Task::class);
});

test('a task is complete only when done', function () {
    expect(makeTask(Status::InProgress)->isComplete())->toBeFalse()
        ->and(makeTask(Status::Done)->isComplete())->toBeTrue();
});

test('a parent task subtree progress reflects its done descendants', function () {
    $project = Project::factory()->create();
    $parent = Task::factory()->for($project)->create();
    Task::factory()->for($project)->childOf($parent)->status(Status::Done)->create();
    Task::factory()->for($project)->childOf($parent)->status(Status::ToDo)->create();

    $progress = $parent->fresh()->progress();

    expect($progress->done)->toBe(1)
        ->and($progress->total)->toBe(2);

    $parent->children()->update(['status' => Status::Done]);

    expect($parent->fresh()->progress()->done)->toBe(2);
});

test('an item is blocked while a blocker is unfinished and unblocked once it is done', function () {
    $task = makeTask();
    $blocker = makeTask(Status::InProgress);

    $task->addBlocker($blocker);

    expect($task->fresh()->isBlocked())->toBeTrue();

    $blocker->status = Status::Done;
    $blocker->save();

    expect($task->fresh()->isBlocked())->toBeFalse();
});

test('an item cannot depend on itself', function () {
    $task = makeTask();

    expect(static fn () => $task->addBlocker($task))->toThrow(InvalidArgumentException::class);
});

test('a direct cycle is rejected', function () {
    $a = makeTask();
    $b = makeTask();

    $a->addBlocker($b);

    expect(static fn () => $b->addBlocker($a))->toThrow(InvalidArgumentException::class);
});

test('a transitive cycle is rejected', function () {
    $a = makeTask();
    $b = makeTask();
    $c = makeTask();

    $a->addBlocker($b);
    $b->addBlocker($c);

    // c -> a would close the a -> b -> c -> a loop.
    expect(static fn () => $c->addBlocker($a))->toThrow(InvalidArgumentException::class);
});

test('adding the same blocker twice is idempotent', function () {
    $task = makeTask();
    $blocker = makeTask();

    $task->addBlocker($blocker);
    $task->addBlocker($blocker);

    expect($task->fresh()->blockers())->toHaveCount(1);
});

test('removing a blocker deletes the link', function () {
    $task = makeTask();
    $blocker = makeTask();

    $task->addBlocker($blocker);
    $task->removeBlocker($blocker);

    expect($task->fresh()->blockers())->toHaveCount(0);
});

test('deleting an item removes its dependency links in both directions', function () {
    $task = makeTask();
    $blocker = makeTask();
    $blocked = makeTask();

    $task->addBlocker($blocker);
    $blocked->addBlocker($task);

    $task->delete();

    expect(Dependency::count())->toBe(0);
});
