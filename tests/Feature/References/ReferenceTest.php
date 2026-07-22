<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Reference;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a task to a doc, with a backlink on the doc', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();

    $task->addReference($doc);

    expect($task->references())->toHaveCount(1)
        ->and($task->references()->first()->is($doc))->toBeTrue()
        ->and($doc->referencedBy())->toHaveCount(1)
        ->and($doc->referencedBy()->first()->is($task))->toBeTrue()
        // Directed: the task itself has no backlinks and the doc references nothing.
        ->and($task->referencedBy())->toBeEmpty()
        ->and($doc->references())->toBeEmpty();
});

it('links a doc to a task', function () {
    $project = Project::factory()->create();
    $doc = Doc::factory()->for($project)->create();
    $task = Task::factory()->for($project)->create();

    $doc->addReference($task);

    expect($doc->references()->first()->is($task))->toBeTrue()
        ->and($task->referencedBy()->first()->is($doc))->toBeTrue();
});

it('allows circular references (no cycle guard)', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();

    $task->addReference($doc);
    $doc->addReference($task);

    expect($task->references()->first()->is($doc))->toBeTrue()
        ->and($doc->references()->first()->is($task))->toBeTrue()
        ->and(Reference::count())->toBe(2);
});

it('rejects referencing itself but allows a same-id item of another type', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();

    // First row in each table: the numeric ids collide but the types differ —
    // exactly the case the self-reference guard must NOT block.
    expect($task->id)->toBe($doc->id);

    expect(fn () => $task->addReference($task))->toThrow(InvalidArgumentException::class);

    $task->addReference($doc);

    expect($task->references()->first()->is($doc))->toBeTrue();
});

it('is idempotent — adding the same reference twice stores one row', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();

    $task->addReference($doc);
    $task->addReference($doc);

    expect(Reference::count())->toBe(1)
        ->and($task->references())->toHaveCount(1);
});

it('removes only the outgoing reference, leaving the reverse link', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();

    $task->addReference($doc);
    $doc->addReference($task);

    $task->removeReference($doc);

    expect($task->references())->toBeEmpty()
        ->and($doc->references()->first()->is($task))->toBeTrue()
        ->and(Reference::count())->toBe(1);
});

it('cleans up an item\'s references on both sides when it is deleted', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $docA = Doc::factory()->for($project)->create();
    $docB = Doc::factory()->for($project)->create();

    $task->addReference($docA);
    $docB->addReference($task);

    expect(Reference::count())->toBe(2);

    $task->delete();

    expect(Reference::count())->toBe(0);
});

it('supports references between two tasks and between two docs', function () {
    $project = Project::factory()->create();
    $t1 = Task::factory()->for($project)->create();
    $t2 = Task::factory()->for($project)->create();
    $d1 = Doc::factory()->for($project)->create();
    $d2 = Doc::factory()->for($project)->create();

    $t1->addReference($t2);
    $d1->addReference($d2);

    expect($t1->references()->first()->is($t2))->toBeTrue()
        ->and($t2->referencedBy()->first()->is($t1))->toBeTrue()
        ->and($d1->references()->first()->is($d2))->toBeTrue()
        ->and($d2->referencedBy()->first()->is($d1))->toBeTrue();
});
