<?php

namespace App\Mcp\Tools;

use App\Models\Attachment;
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

#[Description('Gets a single story by its reference (e.g. "PROJ1"), including its tags and its tasks. Only stories in projects the authenticated user is a member of are accessible.')]
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

        $story->loadMissing(['tags', 'tasks.tags', 'attachments']);

        return Response::structured([
            'reference' => $story->reference,
            'title' => $story->title,
            'description' => $story->description,
            'priority' => $story->priority->name,
            'due_date' => $story->due_date?->format('Y-m-d'),
            'project' => $story->project->short_name,
            'tags' => $story->tags->pluck('name')->all(),
            'tasks' => $story->tasks->map(static fn (Task $task): array => [
                'reference' => $task->reference,
                'title' => $task->title,
                'priority' => $task->priority->name,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'status' => $task->status->value,
                'tags' => $task->tags->pluck('name')->all(),
            ])->all(),
            'attachments' => $story->attachments->map(static fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'is_inline' => $attachment->is_inline,
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
            'priority' => $schema->string()->description('The story priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The story due date in "YYYY-MM-DD" format; may be null.'),
            'project' => $schema->string()->description('The short name of the project the story belongs to.')->required(),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the story.')->required(),
            'tasks' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The task reference, e.g. "PROJ1-3".')->required(),
                'title' => $schema->string()->description('The task title.')->required(),
                'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
                'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
                'status' => $schema->string()->description('The task status.')->required(),
                'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
            ]))->description('The tasks in the story.')->required(),
            'attachments' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->description('The attachment id; pass it to the get-attachment tool to read the file.')->required(),
                'name' => $schema->string()->description('The attachment file name.')->required(),
                'mime_type' => $schema->string()->description('The attachment MIME type; may be null.'),
                'is_inline' => $schema->boolean()->description('Whether the attachment is embedded inline in the description.')->required(),
            ]))->description('The files attached to the story, including inline description images.')->required(),
        ];
    }
}
