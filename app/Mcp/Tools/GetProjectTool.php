<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ExposesComments;
use App\Mcp\Concerns\PagesResults;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single project by its short_name, including its top-level tasks. Only projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetProjectTool extends Tool
{
    use ExposesComments;
    use PagesResults;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'short_name' => ['required', 'string'],
            ...$this->pagingRules(),
        ], [
            'short_name.required' => 'You must provide the project short_name (e.g. "PROJ").',
        ]);

        $user = $request->user();

        $project = Project::query()
            ->where('short_name', $validated['short_name'])
            ->with(['attachments', 'comments.user'])
            ->first();

        if ($project === null || ! $user->can('view', $project)) {
            return Response::error('No project named "'.$validated['short_name'].'" exists, or you do not have access to it.');
        }

        $offset = $this->decodePageCursor($validated['cursor'] ?? null);

        if ($offset === null) {
            return Response::error('The "cursor" is not a valid pagination cursor. Use the page.next_cursor from a previous response.');
        }

        $limit = $validated['limit'] ?? null;

        $fetched = $project->rootTasks()
            ->with('project')
            ->orderBy('task_number')
            ->when($limit !== null, static fn ($builder) => $builder->offset($offset)->limit($limit + 1))
            ->get();

        [$rows, $hasMore] = $this->sliceFetchedPage($fetched, $limit);

        $tasks = $rows->map(static fn (Task $task): array => [
            'reference' => $task->reference,
            'title' => $task->title,
            'status' => $task->status->value,
        ])->values();

        return Response::structured([
            'short_name' => $project->short_name,
            'title' => $project->title,
            'description' => $project->description,
            'tasks' => $tasks->all(),
            'tasks_page' => $this->pageMeta($offset, $limit, $tasks->count(), $hasMore),
            'attachments' => $project->attachments->map(static fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'is_inline' => $attachment->is_inline,
            ])->all(),
            'comments' => $this->commentsPayload($project),
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
                ->description('The project short_name, 2-4 uppercase letters (e.g. "PROJ").')
                ->required(),

            ...$this->pagingSchema($schema, 'top-level tasks'),
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
            'short_name' => $schema->string()->description('The project short name.')->required(),
            'title' => $schema->string()->description('The project title.')->required(),
            'description' => $schema->string()->description('The project description as HTML; may be null.'),
            'tasks' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The task reference, e.g. "PROJ-42".')->required(),
                'title' => $schema->string()->description('The task title.')->required(),
                'status' => $schema->string()->description('The task status.')->required(),
            ]))->description('The top-level tasks in the project.')->required(),
            'tasks_page' => $this->pageSchema($schema),
            'attachments' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->description('The attachment id; pass it to the get-attachment tool to read the file.')->required(),
                'name' => $schema->string()->description('The attachment file name.')->required(),
                'mime_type' => $schema->string()->description('The attachment MIME type; may be null.'),
                'is_inline' => $schema->boolean()->description('Whether the attachment is embedded inline in the description.')->required(),
            ]))->description('The files attached to the project, including inline description images.')->required(),
            'comments' => $this->commentsSchema($schema),
        ];
    }
}
