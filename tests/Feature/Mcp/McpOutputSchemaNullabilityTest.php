<?php

use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\ConvertNoteTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetNoteTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\GetUserTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use Illuminate\Support\Arr;

/**
 * MCP clients (Claude Desktop among them) validate a tool's structuredContent
 * strictly against its declared outputSchema. A field the payload emits as
 * null must therefore be declared nullable — merely omitting required() only
 * permits an absent key, not an explicit null, and the mismatch makes strict
 * clients reject the entire tool call (KAN-413).
 */

/**
 * Serialize a tool's output schema the same way Laravel MCP advertises it.
 *
 * @return array<string, mixed>
 */
function serializedOutputSchema(string $toolClass): array
{
    return Arr::get(app($toolClass)->toArray(), 'outputSchema', []);
}

it('declares every null-capable output field as nullable', function (string $toolClass, array $paths) {
    $schema = serializedOutputSchema($toolClass);

    foreach ($paths as $path) {
        $type = Arr::get($schema, 'properties.'.$path.'.type');

        expect($type)
            ->toBeArray("[$toolClass] $path must be declared nullable")
            ->toContain('null');
    }
})->with([
    'list-tasks' => [ListTasksTool::class, [
        'tasks.items.properties.parent',
        'tasks.items.properties.due_date',
        'tasks.items.properties.type',
        'tasks.items.properties.cancel_reason',
        'page.properties.next_cursor',
    ]],
    'get-task' => [GetTaskTool::class, [
        'description', 'due_date', 'type', 'cancel_reason', 'cancel_message', 'parent',
        'attachments.items.properties.mime_type',
    ]],
    'create-task' => [CreateTaskTool::class, ['description', 'due_date', 'type', 'parent']],
    'update-task' => [UpdateTaskTool::class, ['description', 'due_date', 'type', 'cancel_reason', 'cancel_message']],
    'list-notes' => [ListNotesTool::class, [
        'notes.items.properties.project',
        'notes.items.properties.converted_task',
        'page.properties.next_cursor',
    ]],
    'get-note' => [GetNoteTool::class, ['body', 'project', 'converted_task']],
    'create-note' => [CreateNoteTool::class, ['body', 'project', 'converted_task']],
    'list-projects' => [ListProjectsTool::class, [
        'projects.items.properties.description',
    ]],
    'get-project' => [GetProjectTool::class, [
        'description',
        'attachments.items.properties.mime_type',
    ]],
    'create-project' => [CreateProjectTool::class, ['description']],
    'get-user' => [GetUserTool::class, ['email']],
    'add-comment' => [AddCommentTool::class, ['parent_id']],
]);

it('keeps the convert tool\'s converted_task required and non-null', function () {
    $schema = serializedOutputSchema(ConvertNoteTool::class);

    expect(Arr::get($schema, 'properties.converted_task.type'))->toBe('string')
        ->and(Arr::get($schema, 'required'))->toContain('converted_task');
});
