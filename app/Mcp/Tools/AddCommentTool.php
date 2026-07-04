<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Support\Facades\Audit;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Adds a comment to a project ("PROJ") or task ("PROJ-42"), identified by its reference. Requires a write-access token; the user must be a member of the project.')]
class AddCommentTool extends Tool
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
            'reference' => ['required', 'string'],
            'body' => ['required', 'string', 'max:5000'],
            'reply_to' => ['nullable', 'integer'],
        ], [
            'reference.required' => 'You must provide the reference of the project ("PROJ") or task ("PROJ-42") to comment on.',
            'body.required' => 'You must provide the comment body.',
        ]);

        $commentable = ReferenceResolver::commentable($validated['reference']);

        $project = $commentable instanceof Task ? $commentable->project : $commentable;

        if ($commentable === null || ! $request->user()->can('create-comment', $project)) {
            return Response::error('No project or task with reference "'.$validated['reference'].'" exists, or you do not have access to it.');
        }

        $parentId = null;

        if (! empty($validated['reply_to'])) {
            $parent = $commentable->comments()->whereKey($validated['reply_to'])->first();

            if ($parent === null) {
                return Response::error('No comment with id '.$validated['reply_to'].' exists on "'.$validated['reference'].'" to reply to.');
            }

            // Keep threads one level deep: a reply to a reply attaches to the root.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        /** @var Comment $comment */
        $comment = $commentable->comments()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'body' => $validated['body'],
            'parent_id' => $parentId,
        ]);

        Audit::record($commentable->contentAuditEvent('commented'));

        return Response::structured([
            'id' => $comment->id,
            'on' => $commentable instanceof Project ? $commentable->short_name : $commentable->reference,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
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
                ->description('The reference of the item to comment on: a project ("PROJ") or task ("PROJ-42").')
                ->required(),

            'body' => $schema->string()
                ->description('The comment body, as HTML (sanitized to a small allow-list; unsupported tags are dropped).')
                ->required(),

            'reply_to' => $schema->integer()
                ->description('Optional id of the comment to reply to (from the comments array on get-task/get-project). Threads stay one level deep: replying to a reply attaches to the root comment instead.'),
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
            'id' => $schema->integer()->description('The created comment id.')->required(),
            'on' => $schema->string()->description('The reference of the item the comment was added to.')->required(),
            'parent_id' => $schema->integer()->description('The id of the comment this one replies to, or null for a top-level comment.'),
            'body' => $schema->string()->description('The comment body as HTML.')->required(),
        ];
    }
}
