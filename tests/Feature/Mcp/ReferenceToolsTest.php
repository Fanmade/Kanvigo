<?php

use App\Enums\ReferenceOrigin;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\AddReferenceTool;
use App\Mcp\Tools\GetDocTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\RemoveReferenceTool;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Reference;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

// add-reference

it('links a task to a doc and reports the link', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->published()->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $doc->reference,
    ])->assertOk()->assertSee($doc->reference);

    expect($task->references()->first()?->is($doc))->toBeTrue()
        ->and($doc->referencedBy()->first()?->is($task))->toBeTrue()
        ->and(Reference::firstOrFail()->origin)->toBe(ReferenceOrigin::Manual);
});

it('links a doc to a task in another project the user can see', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $other = Project::factory()->withMembers([$this->editor])->create(['short_name' => 'XYZ']);
    $doc = Doc::factory()->for($this->project)->create();
    $task = Task::factory()->for($other)->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $doc->reference,
        'related_reference' => $task->reference,
    ])->assertOk();

    expect($doc->references()->first()?->is($task))->toBeTrue();
});

it('errors linking an item to itself', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $task->reference,
    ])->assertHasErrors();

    expect(Reference::count())->toBe(0);
});

it('errors linking to an item the user cannot see', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $hidden = Task::factory()->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $hidden->reference,
    ])->assertHasErrors();

    expect(Reference::count())->toBe(0);
});

it('errors linking from a draft doc the user cannot edit', function () {
    Sanctum::actingAs($this->viewer, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->published()->create();
    $task = Task::factory()->for($this->project)->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $doc->reference,
        'related_reference' => $task->reference,
    ])->assertHasErrors();

    expect(Reference::count())->toBe(0);
});

it('errors linking with a read-only token', function () {
    Sanctum::actingAs($this->editor, ['read']);
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $doc->reference,
    ])->assertHasErrors();

    expect(Reference::count())->toBe(0);
});

it('errors on a malformed reference', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();

    KanvigoServer::tool(AddReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => 'nonsense',
    ])->assertHasErrors();
});

// remove-reference

it('unlinks a curated link, leaving the opposite direction in place', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->create();

    $task->addReference($doc);
    $doc->addReference($task);

    KanvigoServer::tool(RemoveReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $doc->reference,
    ])->assertOk();

    expect($task->references())->toBeEmpty()
        ->and($doc->references()->first()?->is($task))->toBeTrue();
});

it('errors unlinking two items that are not linked', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->create();

    KanvigoServer::tool(RemoveReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $doc->reference,
    ])->assertHasErrors();
});

it('errors unlinking with a read-only token', function () {
    Sanctum::actingAs($this->editor, ['read']);
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->create();
    $task->addReference($doc);

    KanvigoServer::tool(RemoveReferenceTool::class, [
        'reference' => $task->reference,
        'related_reference' => $doc->reference,
    ])->assertHasErrors();

    expect($task->references())->toHaveCount(1);
});

it('errors unlinking from an item that does not exist', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->create();

    KanvigoServer::tool(RemoveReferenceTool::class, [
        'reference' => 'ABC-999',
        'related_reference' => $doc->reference,
    ])->assertHasErrors();
});

// references on the read tools

it('reports a task\'s references and backlinks, hiding drafts from a viewer', function () {
    $task = Task::factory()->for($this->project)->create();
    $published = Doc::factory()->for($this->project)->published()->create();
    $draft = Doc::factory()->for($this->project)->create();

    $task->addReference($published);
    $task->addReference($draft);

    KanvigoServer::actingAs($this->viewer)
        ->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee($published->reference)
        ->assertDontSee($draft->reference);
});

it('reports an inline reference written in a body as a link on both items', function () {
    $task = Task::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->published()->create([
        'body' => '<p>'.inlineReference($task).'</p>',
    ]);

    KanvigoServer::actingAs($this->editor)
        ->tool(GetDocTool::class, ['reference' => $doc->reference])
        ->assertOk()
        ->assertSee($task->reference);

    KanvigoServer::actingAs($this->editor)
        ->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee($doc->reference);
});
