<?php

namespace App\Mcp\Tools;

use App\Actions\CreateStory;
use App\Enums\Priority;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a new story in a project, identified by its short_name. Requires a write-access token; the user must be a member of the project.')]
class CreateStoryTool extends Tool
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

        $validated = $request->validate([
            'short_name' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'short_name.required' => 'You must provide the project short_name (e.g. "PROJ").',
            'title.required' => 'You must provide a story title.',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format.',
        ]);

        $project = ReferenceResolver::project($validated['short_name']);

        if ($project === null || ! $request->user()->can('update', $project)) {
            return Response::error('No project named "'.$validated['short_name'].'" exists, or you do not have access to it.');
        }

        $story = app(CreateStory::class)->handle(
            $project,
            $validated['title'],
            $validated['description'] ?? null,
            isset($validated['priority']) ? Priority::fromName($validated['priority']) : null,
            $validated['due_date'] ?? null,
        );

        $story->setRelation('project', $project);

        return Response::structured([
            'reference' => $story->reference,
            'title' => $story->title,
            'description' => $story->description,
            'priority' => $story->priority->name,
            'due_date' => $story->due_date?->format('Y-m-d'),
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
            'short_name' => $schema->string()
                ->description('The short_name of the project to add the story to (e.g. "PROJ").')
                ->required(),

            'title' => $schema->string()
                ->description('The story title.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional story description.'),

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('Optional priority: one of Lowest, Low, Medium, High, Highest. Defaults to Medium.'),

            'due_date' => $schema->string()
                ->description('Optional due date in "YYYY-MM-DD" format.'),
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
            'reference' => $schema->string()->description('The created story reference, e.g. "PROJ1".')->required(),
            'title' => $schema->string()->description('The created story title.')->required(),
            'description' => $schema->string()->description('The story description; may be null.'),
            'priority' => $schema->string()->description('The story priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The story due date in "YYYY-MM-DD" format; may be null.'),
        ];
    }
}
