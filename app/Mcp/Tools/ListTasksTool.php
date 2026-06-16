<?php

namespace App\Mcp\Tools;

use App\Enums\Status;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the tasks of a story, identified by its reference (e.g. "PROJ1"), optionally filtered by status. Only stories in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class ListTasksTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $statuses = implode('", "', array_map(static fn (Status $status): string => $status->value, Status::cases()));

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'status' => ['nullable', new Enum(Status::class)],
        ], [
            'reference.required' => 'You must provide the story reference, formed from the project short_name and the story number (e.g. "PROJ1").',
            'status' => 'The status filter must be one of "'.$statuses.'".',
        ]);

        $story = ReferenceResolver::story($validated['reference']);

        if ($story === null || ! $request->user()->can('view', $story)) {
            return Response::error('No story with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1".');
        }

        $tasks = $story->tasks
            ->when(
                isset($validated['status']),
                static fn ($tasks) => $tasks->where('status', Status::from($validated['status']))
            )
            ->map(static fn (Task $task): array => [
                'reference' => $task->reference,
                'title' => $task->title,
                'status' => $task->status->value,
            ])
            ->values();

        return Response::structured([
            'tasks' => $tasks->all(),
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

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('Optional status filter. One of the task statuses.'),
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
            'tasks' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The task reference, e.g. "PROJ1-3".')->required(),
                'title' => $schema->string()->description('The task title.')->required(),
                'status' => $schema->string()->description('The task status.')->required(),
            ]))->description('The tasks in the story.')->required(),
        ];
    }
}
