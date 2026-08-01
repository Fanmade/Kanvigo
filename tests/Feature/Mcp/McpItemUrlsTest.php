<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\CreateDocTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetDocTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListDocsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A reference such as "ABC-1" carries no domain, so the tools return the item's
 * absolute URL on this instance — otherwise a client asked for a link has to
 * guess a domain, and self-hosted instances get links to somewhere else.
 */
beforeEach(function () {
    config()->set('app.url', 'https://board.example.test');
    URL::forceRootUrl('https://board.example.test');
    URL::forceScheme('https');

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
});

it('returns the task url built from this instance url', function () {
    $task = Task::factory()->for($this->project)->create();

    KanvigoServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC-'.$task->task_number)
            ->etc());
});

it('returns the doc url built from this instance url', function () {
    $doc = Doc::factory()->for($this->project)->published()->create();

    KanvigoServer::actingAs($this->member)
        ->tool(GetDocTool::class, ['reference' => $doc->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC-D'.$doc->doc_number)
            ->etc());
});

it('returns the project url built from this instance url', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC')
            ->etc());
});

it('returns the url of a task it creates and updates', function () {
    Sanctum::actingAs($this->member, ['read', 'write']);

    KanvigoServer::tool(CreateTaskTool::class, ['reference' => 'ABC', 'title' => 'Linkable'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC-1')
            ->etc());

    KanvigoServer::tool(UpdateTaskTool::class, ['reference' => 'ABC-1', 'title' => 'Renamed'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC-1')
            ->etc());
});

it('returns the url of a doc it creates', function () {
    Sanctum::actingAs($this->member, ['read', 'write']);

    KanvigoServer::tool(CreateDocTool::class, ['reference' => 'ABC', 'title' => 'Spec'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/ABC-D1')
            ->etc());
});

it('returns the url of a project it creates and updates', function () {
    $creator = User::factory()->canCreateProjects()->create();
    Sanctum::actingAs($creator, ['read', 'write']);

    KanvigoServer::tool(CreateProjectTool::class, ['title' => 'New Project', 'short_name' => 'NEW'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/NEW')
            ->etc());

    KanvigoServer::tool(UpdateProjectTool::class, ['short_name' => 'NEW', 'title' => 'Renamed'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('url', 'https://board.example.test/NEW')
            ->etc());
});

it('returns a url for every task the list tool reports', function () {
    $task = Task::factory()->for($this->project)->create();

    // Listing is where an agent usually meets a task; without a url here it
    // would have to call get-task again just to link it.
    KanvigoServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('tasks.0', fn ($row) => $row
                ->where('url', 'https://board.example.test/ABC-'.$task->task_number)
                ->etc())
            ->etc());
});

it('returns a url for every doc the list tool reports', function () {
    $doc = Doc::factory()->for($this->project)->published()->create();

    KanvigoServer::actingAs($this->member)
        ->tool(ListDocsTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('docs.0', fn ($row) => $row
                ->where('url', 'https://board.example.test/ABC-D'.$doc->doc_number)
                ->etc())
            ->etc());
});

it('returns a url for every project the list tool reports', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(ListProjectsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('projects.0', fn ($row) => $row
                ->where('url', 'https://board.example.test/ABC')
                ->etc())
            ->etc());
});

it('returns a url for the tasks listed inside a project', function () {
    $task = Task::factory()->for($this->project)->create();

    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('tasks.0', fn ($row) => $row
                ->where('url', 'https://board.example.test/ABC-'.$task->task_number)
                ->etc())
            ->etc());
});
