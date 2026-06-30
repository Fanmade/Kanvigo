<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pull a value out of a tool response's structured content by dot path. The
 * TestResponse keeps the raw response protected, so reach it via a bound closure.
 */
function structured(object $response, string $path): mixed
{
    $content = (fn () => $this->response->toArray()['result']['structuredContent'] ?? [])->call($response);

    return data_get($content, $path);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ABC']);
});

it('returns every task and signals no more when no limit is given', function () {
    Task::factory()->count(3)->for($this->project)->create();

    KanvigoServer::actingAs($this->user)->tool(ListTasksTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('page.returned', 3)
            ->where('page.has_more', false)
            ->where('page.next_cursor', null)
            ->has('tasks', 3)
            ->etc());
});

it('caps tasks to the limit and walks the rest with the cursor', function () {
    // task_number is assigned in creation order, so paging is deterministic.
    $tasks = collect(range(1, 5))->map(fn () => Task::factory()->for($this->project)->create());
    $references = $tasks->pluck('reference')->all();

    $first = KanvigoServer::actingAs($this->user)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'limit' => 2]);

    $first->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('page.returned', 2)
            ->where('page.has_more', true)
            ->where('tasks.0.reference', $references[0])
            ->where('tasks.1.reference', $references[1])
            ->etc());

    $cursor = structured($first, 'page.next_cursor');
    expect($cursor)->not->toBeNull();

    KanvigoServer::actingAs($this->user)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'limit' => 2, 'cursor' => $cursor])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('page.returned', 2)
            ->where('page.has_more', true)
            ->where('tasks.0.reference', $references[2])
            ->where('tasks.1.reference', $references[3])
            ->etc());
});

it('reports has_more false on the final page', function () {
    Task::factory()->count(3)->for($this->project)->create();

    KanvigoServer::actingAs($this->user)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'limit' => 5])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('page.returned', 3)
            ->where('page.has_more', false)
            ->where('page.next_cursor', null)
            ->etc());
});

it('errors on a malformed cursor', function () {
    Task::factory()->for($this->project)->create();

    KanvigoServer::actingAs($this->user)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'limit' => 1, 'cursor' => 'not-a-cursor'])
        ->assertHasErrors();
});

it('rejects a limit above the maximum', function () {
    KanvigoServer::actingAs($this->user)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'limit' => 9999])
        ->assertHasErrors();
});

it('pages the root tasks of a project via get-project', function () {
    Task::factory()->count(3)->for($this->project)->create();

    KanvigoServer::actingAs($this->user)
        ->tool(GetProjectTool::class, ['short_name' => 'ABC', 'limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('tasks_page.returned', 2)
            ->where('tasks_page.has_more', true)
            ->has('tasks', 2)
            ->etc());
});

it('pages the notes list', function () {
    Note::factory()->count(3)->for($this->user)->create();

    KanvigoServer::actingAs($this->user)
        ->tool(ListNotesTool::class, ['limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('page.returned', 2)
            ->where('page.has_more', true)
            ->has('notes', 2)
            ->etc());
});
