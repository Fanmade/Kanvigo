<?php

namespace App\Mcp\Tools;

use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
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
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', new Enum(Status::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the story reference to add the task to (e.g. "PROJ1").',
            'title.required' => 'You must provide a task title.',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format.',
            'status' => 'The status must be one of "'.$statuses.'".',
        ]);

        $story = ReferenceResolver::story($validated['reference']);

        if ($story === null || ! $request->user()->can('update', $story)) {
            return Response::error('No story with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1".');
        }

        $task = app(CreateTask::class)->handle(
            $story,
            $validated['title'],
            $validated['description'] ?? null,
            isset($validated['priority']) ? Priority::fromName($validated['priority']) : null,
            isset($validated['status']) ? Status::from($validated['status']) : null,
            $validated['due_date'] ?? null,
        );

        $task->setRelation('story', $story);

        if (isset($validated['tags'])) {
            $task->syncTags($validated['tags']);
        }

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority->name,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'status' => $task->status->value,
            'tags' => $task->tags()->pluck('name')->all(),
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

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('Optional priority: one of Lowest, Low, Medium, High, Highest. Defaults to the parent story\'s priority.'),

            'due_date' => $schema->string()
                ->description('Optional due date in "YYYY-MM-DD" format.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('Optional initial status. Defaults to "Planned".'),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional tags to apply, as an array of tag names (e.g. ["UI/UX", "bug"]). Tags that do not exist yet are created.'),
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
            'reference' => $schema->string()->description('The created task reference, e.g. "PROJ-42".')->required(),
            'title' => $schema->string()->description('The created task title.')->required(),
            'description' => $schema->string()->description('The task description; may be null.'),
            'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
        ];
    }
}
