<?php

namespace App\Mcp\Tools;

use App\Enums\Priority;
use App\Mcp\Concerns\RecordsTagChanges;
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

#[Description('Updates a story\'s title, description, priority and/or due date, identified by its reference (e.g. "PROJ1"). Requires a write-access token; the user must be a member of the project.')]
class UpdateStoryTool extends Tool
{
    use RecordsTagChanges;
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
            'reference' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the story reference (e.g. "PROJ1").',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format. Pass null to clear it.',
        ]);

        $story = ReferenceResolver::story($validated['reference']);

        if ($story === null || ! $request->user()->can('update', $story)) {
            return Response::error('No story with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1".');
        }

        $updates = [];

        if ($request->has('title')) {
            $updates['title'] = $validated['title'];
        }

        if ($request->has('description')) {
            $updates['description'] = $validated['description'];
        }

        if ($request->has('priority') && isset($validated['priority'])) {
            $updates['priority'] = Priority::fromName($validated['priority']);
        }

        if ($request->has('due_date')) {
            $updates['due_date'] = $validated['due_date'];
        }

        $tagsProvided = $request->has('tags');

        if ($updates === [] && ! $tagsProvided) {
            return Response::error('Provide a title, description, priority, due date and/or tags to update.');
        }

        if ($updates !== []) {
            $story->update($updates);
        }

        if ($tagsProvided) {
            $this->recordTagSync($story, $story->syncTags($validated['tags'] ?? []));
        }

        return Response::structured([
            'reference' => $story->reference,
            'title' => $story->title,
            'description' => $story->description,
            'priority' => $story->priority->name,
            'due_date' => $story->due_date?->format('Y-m-d'),
            'tags' => $story->tags()->pluck('name')->all(),
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
                ->description('The reference of the story to update (e.g. "PROJ1").')
                ->required(),

            'title' => $schema->string()
                ->description('New title for the story.'),

            'description' => $schema->string()
                ->description('New description for the story.'),

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('New priority: one of Lowest, Low, Medium, High, Highest.'),

            'due_date' => $schema->string()
                ->description('New due date in "YYYY-MM-DD" format. Pass null to clear it.'),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('The complete set of tags for the story, as an array of tag names (e.g. ["UI/UX", "bug"]). Replaces the existing tags; pass [] to clear them. Tags that do not exist yet are created.'),
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
            'title' => $schema->string()->description('The updated story title.')->required(),
            'description' => $schema->string()->description('The updated story description; may be null.'),
            'priority' => $schema->string()->description('The story priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The story due date in "YYYY-MM-DD" format; may be null.'),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the story.')->required(),
        ];
    }
}
