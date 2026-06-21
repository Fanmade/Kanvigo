<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a root task in a fresh project.
 */
function makeRootTask(): Task
{
    return Task::factory()->for(Project::factory())->create();
}

test('a task can have a parent and children', function () {
    $parent = makeRootTask();
    $child = Task::factory()->childOf($parent)->create();

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children->pluck('id'))->toContain($child->id);
});

test('ancestors and descendants resolve across multiple levels', function () {
    $root = makeRootTask();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    expect($root->descendants()->pluck('id'))->toContain($child->id, $grandchild->id)
        ->and($grandchild->ancestors()->pluck('id'))->toContain($child->id, $root->id)
        ->and($grandchild->nestingDepth())->toBe(3)
        ->and($root->subtreeHeight())->toBe(3);
});

test('a root task has no parent and is allowed', function () {
    $task = makeRootTask();

    expect($task->parent)->toBeNull()
        ->and($task->nestingDepth())->toBe(1);
});

test('a task cannot be its own parent', function () {
    $task = makeRootTask();
    $task->parent_id = $task->id;

    expect(static fn () => $task->save())->toThrow(InvalidArgumentException::class);
});

test('a direct cycle is rejected', function () {
    $parent = makeRootTask();
    $child = Task::factory()->childOf($parent)->create();

    // Nesting the parent under its own child closes a cycle.
    $parent->parent_id = $child->id;

    expect(static fn () => $parent->save())->toThrow(InvalidArgumentException::class);
});

test('a transitive cycle is rejected', function () {
    $root = makeRootTask();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    // root -> grandchild would close the root -> child -> grandchild loop.
    $root->parent_id = $grandchild->id;

    expect(static fn () => $root->save())->toThrow(InvalidArgumentException::class);
});

test('the depth limit is enforced when creating a task', function () {
    config(['kanbrio.tasks.max_depth' => 3]);

    $root = makeRootTask();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    expect(static fn () => Task::factory()->childOf($grandchild)->create())
        ->toThrow(InvalidArgumentException::class);
});

test('the depth limit is enforced when moving a subtree', function () {
    config(['kanbrio.tasks.max_depth' => 3]);

    // A two-level subtree: branch -> leaf.
    $branchRoot = makeRootTask();
    $leaf = Task::factory()->childOf($branchRoot)->create();

    // A target at depth 2; attaching the 2-tall subtree under it reaches depth 4.
    $root = makeRootTask();
    $deepTarget = Task::factory()->childOf($root)->create();

    $branchRoot->parent_id = $deepTarget->id;

    expect(static fn () => $branchRoot->save())->toThrow(InvalidArgumentException::class);
});

test('a subtree can be moved while it stays within the depth limit', function () {
    config(['kanbrio.tasks.max_depth' => 3]);

    $branchRoot = makeRootTask();
    $leaf = Task::factory()->childOf($branchRoot)->create();

    // A root target: the 2-tall subtree reaches depth 2, within the limit.
    $target = makeRootTask();

    $branchRoot->parent_id = $target->id;
    $branchRoot->save();

    expect($branchRoot->fresh()->parent->is($target))->toBeTrue()
        ->and($leaf->fresh()->nestingDepth())->toBe(3);
});

test('the maximum depth is configurable', function () {
    config(['kanbrio.tasks.max_depth' => 2]);

    $root = makeRootTask();
    $child = Task::factory()->childOf($root)->create();

    expect(static fn () => Task::factory()->childOf($child)->create())
        ->toThrow(InvalidArgumentException::class);
});

test('deleting a parent cascades to its descendants', function () {
    $root = makeRootTask();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    $root->delete();

    expect(Task::whereIn('id', [$child->id, $grandchild->id])->count())->toBe(0);
});
