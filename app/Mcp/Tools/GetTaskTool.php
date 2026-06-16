<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single task by its reference (e.g. "PROJ1-3"), including status, description, assignees and its story/project references. Only tasks in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetTaskTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the task reference, formed from the story reference and the task number (e.g. "PROJ1-3").',
        ]);

        $task = ReferenceResolver::task($validated['reference']);

        if ($task === null || ! $request->user()->can('view', $task)) {
            return Response::error('No task with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1-3".');
        }

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'story' => $task->story->reference,
            'project' => $task->story->project->short_name,
            'assignees' => $task->assignees->map(static fn (User $user): array => [
                'name' => $user->name,
                'email' => $user->email,
            ])->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The task reference: the story reference followed by a dash and the task number (e.g. "PROJ1-3").')
                ->required(),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The task reference, e.g. "PROJ1-3".')->required(),
            'title' => $schema->string()->description('The task title.')->required(),
            'description' => $schema->string()->description('The task description; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
            'story' => $schema->string()->description('The reference of the story the task belongs to, e.g. "PROJ1".')->required(),
            'project' => $schema->string()->description('The short name of the project the task belongs to.')->required(),
            'assignees' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('The assignee name.')->required(),
                'email' => $schema->string()->description('The assignee email address.')->required(),
            ]))->description('The users assigned to the task.')->required(),
        ];
    }
}
