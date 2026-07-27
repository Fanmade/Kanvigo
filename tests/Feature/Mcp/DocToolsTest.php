<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\CreateDocTool;
use App\Mcp\Tools\GetDocTool;
use App\Mcp\Tools\ListDocsTool;
use App\Mcp\Tools\UpdateDocTool;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

it('advertises the doc and reference tools on the server', function () {
    Sanctum::actingAs($this->editor, ['read']);

    $response = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toContain(
        'list-docs-tool',
        'get-doc-tool',
        'create-doc-tool',
        'update-doc-tool',
        'add-reference-tool',
        'remove-reference-tool',
    );
});

// list-docs

it('lists a project docs with their nesting', function () {
    $parent = Doc::factory()->for($this->project)->published()->create(['title' => 'Architecture']);
    $child = Doc::factory()->childOf($parent)->published()->create(['title' => 'Storage']);

    KanvigoServer::actingAs($this->editor)
        ->tool(ListDocsTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertSee($parent->reference)
        ->assertSee('Storage')
        ->assertSee($child->reference);
});

it('lists only the docs nested under a parent when asked', function () {
    $parent = Doc::factory()->for($this->project)->published()->create();
    $child = Doc::factory()->childOf($parent)->published()->create(['title' => 'Storage']);
    $elsewhere = Doc::factory()->for($this->project)->published()->create(['title' => 'Glossary']);

    KanvigoServer::actingAs($this->editor)
        ->tool(ListDocsTool::class, ['reference' => 'ABC', 'parent' => $parent->reference])
        ->assertOk()
        ->assertSee($child->reference)
        ->assertDontSee($elsewhere->reference);
});

it('hides drafts from a member who cannot edit docs', function () {
    $draft = Doc::factory()->for($this->project)->create(['title' => 'Secret plan']);
    $published = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);

    KanvigoServer::actingAs($this->viewer)
        ->tool(ListDocsTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertSee($published->reference)
        ->assertDontSee('Secret plan')
        ->assertDontSee($draft->reference);
});

it('errors listing docs of a project the user cannot access', function () {
    Project::factory()->create(['short_name' => 'XYZ']);

    KanvigoServer::actingAs($this->editor)
        ->tool(ListDocsTool::class, ['reference' => 'XYZ'])
        ->assertHasErrors();
});

// get-doc

it('gets a doc with its body, nesting and links', function () {
    $doc = Doc::factory()->for($this->project)->published()->create([
        'title' => 'Style guide',
        'body' => '<p>Write plainly.</p>',
    ]);
    $child = Doc::factory()->childOf($doc)->published()->create(['title' => 'Voice']);
    $task = Task::factory()->for($this->project)->create();
    $doc->addReference($task);
    $doc->syncTags(['design']);

    KanvigoServer::actingAs($this->editor)
        ->tool(GetDocTool::class, ['reference' => $doc->reference])
        ->assertOk()
        ->assertSee('Write plainly.')
        ->assertSee($child->reference)
        ->assertSee($task->reference)
        ->assertSee('design');
});

it('reports the backlinks of a doc', function () {
    $doc = Doc::factory()->for($this->project)->published()->create();
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>See '.inlineReference($doc).'</p>',
    ]);

    KanvigoServer::actingAs($this->editor)
        ->tool(GetDocTool::class, ['reference' => $doc->reference])
        ->assertOk()
        ->assertSee($task->reference);
});

it('errors getting a draft doc as a member who cannot edit docs', function () {
    $draft = Doc::factory()->for($this->project)->create();

    KanvigoServer::actingAs($this->viewer)
        ->tool(GetDocTool::class, ['reference' => $draft->reference])
        ->assertHasErrors();
});

it('errors getting a doc that does not exist', function () {
    KanvigoServer::actingAs($this->editor)
        ->tool(GetDocTool::class, ['reference' => 'ABC-D99'])
        ->assertHasErrors();
});

// create-doc

it('creates a draft doc in a project', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);

    KanvigoServer::tool(CreateDocTool::class, [
        'reference' => 'ABC',
        'title' => 'Style guide',
        'body' => '<p>Write plainly.</p>',
    ])->assertOk()->assertSee('ABC-D1');

    assertDatabaseHas('docs', [
        'project_id' => $this->project->id,
        'title' => 'Style guide',
        'is_public' => false,
        'parent_id' => null,
    ]);
});

it('creates a published doc nested under a parent', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $parent = Doc::factory()->for($this->project)->create();

    KanvigoServer::tool(CreateDocTool::class, [
        'reference' => 'ABC',
        'title' => 'Storage',
        'parent' => $parent->reference,
        'public' => true,
    ])->assertOk();

    assertDatabaseHas('docs', ['title' => 'Storage', 'parent_id' => $parent->id, 'is_public' => true]);
});

it('decodes an HTML-escaped ampersand in the doc title', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);

    KanvigoServer::tool(CreateDocTool::class, ['reference' => 'ABC', 'title' => 'Ben &amp; Jerry'])->assertOk();

    assertDatabaseHas('docs', ['title' => 'Ben & Jerry']);
});

it('errors creating a doc with a parent from another project', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $other = Project::factory()->withMembers([$this->editor])->create(['short_name' => 'XYZ']);
    $foreign = Doc::factory()->for($other)->create();

    KanvigoServer::tool(CreateDocTool::class, [
        'reference' => 'ABC',
        'title' => 'Storage',
        'parent' => $foreign->reference,
    ])->assertHasErrors();

    expect(Doc::where('title', 'Storage')->exists())->toBeFalse();
});

it('errors creating a doc without the create-doc permission', function () {
    Sanctum::actingAs($this->viewer, ['read', 'write']);

    KanvigoServer::tool(CreateDocTool::class, ['reference' => 'ABC', 'title' => 'Style guide'])
        ->assertHasErrors();

    expect(Doc::count())->toBe(0);
});

it('errors creating a doc with a read-only token', function () {
    Sanctum::actingAs($this->editor, ['read']);

    KanvigoServer::tool(CreateDocTool::class, ['reference' => 'ABC', 'title' => 'Style guide'])
        ->assertHasErrors();

    expect(Doc::count())->toBe(0);
});

// update-doc

it('updates a doc title, body and published flag', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->create(['title' => 'Old']);

    KanvigoServer::tool(UpdateDocTool::class, [
        'reference' => $doc->reference,
        'title' => 'New',
        'body' => '<p>Fresh</p>',
        'public' => true,
    ])->assertOk()->assertSee('New');

    $doc->refresh();

    expect($doc->title)->toBe('New')
        ->and($doc->body)->toContain('Fresh')
        ->and($doc->is_public)->toBeTrue();
});

it('re-parents a doc and moves it back to the top level', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $parent = Doc::factory()->for($this->project)->create();
    $doc = Doc::factory()->for($this->project)->create();

    KanvigoServer::tool(UpdateDocTool::class, ['reference' => $doc->reference, 'parent' => $parent->reference])->assertOk();
    expect($doc->refresh()->parent_id)->toBe($parent->id);

    KanvigoServer::tool(UpdateDocTool::class, ['reference' => $doc->reference, 'parent' => ''])->assertOk();
    expect($doc->refresh()->parent_id)->toBeNull();
});

it('leaves omitted fields untouched', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Keep', 'body' => '<p>Body</p>']);

    KanvigoServer::tool(UpdateDocTool::class, ['reference' => $doc->reference, 'title' => 'Renamed'])->assertOk();

    $doc->refresh();

    expect($doc->title)->toBe('Renamed')
        ->and($doc->body)->toContain('Body')
        ->and($doc->is_public)->toBeTrue();
});

it('errors nesting a doc under its own nested doc', function () {
    Sanctum::actingAs($this->editor, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->create();
    $child = Doc::factory()->childOf($doc)->create();

    KanvigoServer::tool(UpdateDocTool::class, ['reference' => $doc->reference, 'parent' => $child->reference])
        ->assertHasErrors();

    expect($doc->refresh()->parent_id)->toBeNull();
});

it('errors updating a doc without the edit-doc permission', function () {
    Sanctum::actingAs($this->viewer, ['read', 'write']);
    $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);

    KanvigoServer::tool(UpdateDocTool::class, ['reference' => $doc->reference, 'title' => 'Hijacked'])
        ->assertHasErrors();

    expect($doc->refresh()->title)->toBe('Style guide');
});
