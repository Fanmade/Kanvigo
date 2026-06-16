<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single story by its reference (e.g. "PROJ1"), including its tasks. Only stories in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetStoryTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the story reference, formed from the project short_name and the story number (e.g. "PROJ1").',
        ]);

        $story = ReferenceResolver::story($validated['reference']);

        if ($story === null || ! $request->user()->can('view', $story)) {
            return Response::error('No story with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1".');
        }

        return Response::structured([
            'reference' => $story->reference,
            'title' => $story->title,
            'description' => $story->description,
            'project' => $story->project->short_name,
            'tasks' => $story->tasks->map(static fn (Task $task): array => [
                'reference' => $task->reference,
                'title' => $task->title,
                'status' => $task->status->value,
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
                ->description('The story reference: the project short_name followed by the story number (e.g. "PROJ1").')
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
            'reference' => $schema->string()->description('The story reference, e.g. "PROJ1".')->required(),
            'title' => $schema->string()->description('The story title.')->required(),
            'description' => $schema->string()->description('The story description; may be null.'),
            'project' => $schema->string()->description('The short name of the project the story belongs to.')->required(),
            'tasks' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The task reference, e.g. "PROJ1-3".')->required(),
                'title' => $schema->string()->description('The task title.')->required(),
                'status' => $schema->string()->description('The task status.')->required(),
            ]))->description('The tasks in the story.')->required(),
        ];
    }
}
