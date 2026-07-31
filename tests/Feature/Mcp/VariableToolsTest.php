<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\CreateVariableTool;
use App\Mcp\Tools\GetDocTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListVariablesTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\UpdateVariableTool;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

it('advertises the variable tools on the server', function () {
    Sanctum::actingAs($this->member, ['read']);

    $response = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toContain('list-variables-tool', 'create-variable-tool', 'update-variable-tool')
        // Deleting a variable silently changes what documents show, so there is
        // deliberately no tool for it.
        ->not->toContain('delete-variable-tool');
});

describe('listing variables', function () {
    it('lists a project variables with their values', function () {
        Variable::factory()->for($this->project)->create([
            'name' => 'hero',
            'value' => 'Robin Hood',
            'description' => 'The protagonist',
        ]);
        Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);

        KanvigoServer::actingAs($this->member)
            ->tool(ListVariablesTool::class, ['reference' => 'ABC'])
            ->assertOk()
            ->assertStructuredContent([
                'variables' => [
                    ['name' => 'hero', 'value' => 'Robin Hood', 'description' => 'The protagonist'],
                    ['name' => 'villain', 'value' => null, 'description' => null],
                ],
            ]);
    });

    it('is readable by anyone who can see the project', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

        KanvigoServer::actingAs($this->viewer)
            ->tool(ListVariablesTool::class, ['reference' => 'ABC'])
            ->assertOk()
            ->assertSee('Robin Hood');
    });

    it('errors for a project the caller cannot see', function () {
        Project::factory()->create(['short_name' => 'OTH']);

        KanvigoServer::actingAs($this->member)
            ->tool(ListVariablesTool::class, ['reference' => 'OTH'])
            ->assertHasErrors();
    });
});

describe('the variables sidecar', function () {
    it('returns a task body as stored, with the values alongside it', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>[hero] meets [villain].</p>',
        ]);

        $response = KanvigoServer::actingAs($this->member)
            ->tool(GetTaskTool::class, ['reference' => $task->reference])
            ->assertOk();

        // The stored text keeps the markers — resolving them here would make a
        // read-edit-write round trip delete every usage.
        $response->assertSee('[hero] meets [villain]')
            ->assertSee('Robin Hood')
            ->assertDontSee('Robin Hood meets');
    });

    it('returns a doc body as stored, with the values alongside it', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        $doc = Doc::factory()->for($this->project)->published()->create(['body' => '<p>Meet [hero].</p>']);

        KanvigoServer::actingAs($this->member)
            ->tool(GetDocTool::class, ['reference' => $doc->reference])
            ->assertOk()
            ->assertSee('Meet [hero]')
            ->assertSee('Robin Hood');
    });

    it('lists only the variables the content actually uses', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        Variable::factory()->for($this->project)->create(['name' => 'unused', 'value' => 'Nowhere']);
        $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);

        KanvigoServer::actingAs($this->member)
            ->tool(GetTaskTool::class, ['reference' => $task->reference])
            ->assertOk()
            ->assertDontSee('Nowhere');
    });
});

describe('creating a variable', function () {
    it('creates one with a value', function () {
        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(CreateVariableTool::class, [
            'reference' => 'ABC',
            'name' => 'Main_Protagonist',
            'value' => 'Robin Hood',
        ])
            ->assertOk()
            ->assertSee('[main_protagonist]');

        expect($this->project->variables()->first())
            ->name->toBe('main_protagonist')
            ->value->toBe('Robin Hood');
    });

    it('creates one that is still undecided', function () {
        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(CreateVariableTool::class, ['reference' => 'ABC', 'name' => 'villain'])
            ->assertOk();

        expect($this->project->variables()->first()->isUnset())->toBeTrue();
    });

    it('rejects a name the app would reject', function (string $name) {
        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(CreateVariableTool::class, ['reference' => 'ABC', 'name' => $name])
            ->assertHasErrors();

        expect($this->project->variables()->count())->toBe(0);
    })->with(['1', 'h', 'main protagonist', 'hero!']);

    it('rejects a name the project already uses', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero']);

        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(CreateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero'])
            ->assertHasErrors();

        expect($this->project->variables()->count())->toBe(1);
    });

    it('refuses a caller without the manage-variables permission', function () {
        Sanctum::actingAs($this->viewer, ['read', 'write']);

        KanvigoServer::tool(CreateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero'])
            ->assertHasErrors();

        expect($this->project->variables()->count())->toBe(0);
    });

    it('refuses a read-only token', function () {
        Sanctum::actingAs($this->member, ['read']);

        postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'create-variable-tool', 'arguments' => ['reference' => 'ABC', 'name' => 'hero']],
        ])->assertOk();

        expect($this->project->variables()->count())->toBe(0);
    });
});

describe('updating a variable', function () {
    it('changes what a variable stands for without touching any content', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);

        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero', 'value' => 'Robin of Loxley'])
            ->assertOk();

        expect($this->project->variables()->first()->value)->toBe('Robin of Loxley')
            ->and($task->fresh()->description)->toBe('<p>[hero] arrives.</p>');
    });

    it('returns a variable to undecided', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero', 'value' => ''])
            ->assertOk();

        expect($this->project->variables()->first()->isUnset())->toBeTrue();
    });

    it('renames a variable, rewrites its usages and reports how many', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);
        $doc = Doc::factory()->for($this->project)->create(['body' => '<p>Meet [hero].</p>']);

        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero', 'new_name' => 'lead'])
            ->assertOk()
            ->assertStructuredContent(static fn (AssertableJson $json) => $json
                ->where('renamed_from', 'hero')
                ->where('usages_rewritten', 2)
                ->etc());

        expect($this->project->variables()->first()->name)->toBe('lead')
            ->and($task->fresh()->description)->toContain('[lead]')
            ->and($doc->fresh()->body)->toContain('[lead]');
    });

    it('errors on a variable the project does not define', function () {
        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'ghost', 'value' => 'x'])
            ->assertHasErrors();
    });

    it('rejects renaming onto a name already in use', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero']);
        Variable::factory()->for($this->project)->create(['name' => 'lead']);

        Sanctum::actingAs($this->member, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero', 'new_name' => 'lead'])
            ->assertHasErrors();

        expect($this->project->variables()->pluck('name')->all())->toBe(['hero', 'lead']);
    });

    it('refuses a caller without the manage-variables permission', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

        Sanctum::actingAs($this->viewer, ['read', 'write']);

        KanvigoServer::tool(UpdateVariableTool::class, ['reference' => 'ABC', 'name' => 'hero', 'value' => 'Nobody'])
            ->assertHasErrors();

        expect($this->project->variables()->first()->value)->toBe('Robin Hood');
    });
});

it('never creates a variable from a body that uses an unknown name', function () {
    $task = Task::factory()->for($this->project)->create();

    Sanctum::actingAs($this->member, ['read', 'write']);

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'description' => '<p>Enter [protagonsit].</p>',
    ])
        ->assertOk();

    // A typo must not mint permanent project vocabulary.
    expect($this->project->variables()->count())->toBe(0)
        ->and($task->fresh()->description)->toContain('[protagonsit]');
});
