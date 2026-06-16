<?php

namespace App\Mcp\Tools;

use App\Enums\Status;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a new task in a story, identified by its reference (e.g. "PROJ1"). Requires a write-access token; the user must be a member of the project.')]
class CreateTaskTool extends Tool
{
    use RequiresWriteAccess;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $statuses = implode('", "', array_map(static fn (Status $status): string => $status->value, Status::cases()));

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', new Enum(Status::class)],
        ], [
            'reference.required' => 'You must provide the story reference to add the task to (e.g. "PROJ1").',
            'title.required' => 'You must provide a task title.',
            'status' => 'The status must be one of "'.$statuses.'".',
        ]);

        $story = ReferenceResolver::story($validated['reference']);

        if ($story === null || ! $request->user()->can('update', $story)) {
            return Response::error('No story with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1".');
        }

        $task = $story->tasks()->make([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);
        $task->status = isset($validated['status']) ? Status::from($validated['status']) : Status::Planned;
        $task->save();

        $task->setRelation('story', $story);

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
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
                ->description('The reference of the story to add the task to (e.g. "PROJ1").')
                ->required(),

            'title' => $schema->string()
                ->description('The task title.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional task description.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('Optional initial status. Defaults to "Planned".'),
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
            'reference' => $schema->string()->description('The created task reference, e.g. "PROJ1-3".')->required(),
            'title' => $schema->string()->description('The created task title.')->required(),
            'description' => $schema->string()->description('The task description; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
        ];
    }
}
