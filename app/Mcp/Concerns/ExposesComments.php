<?php

namespace App\Mcp\Concerns;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Serializes a commentable's comment thread for the MCP read tools: each
 * comment's id, author, body and timestamp, ordered oldest first. Replies carry
 * the parent_id of the comment they answer; deleted comments are tombstones kept
 * (with an empty body) only to preserve replies hanging off them.
 */
trait ExposesComments
{
    /**
     * Build the comments payload for a project or task, eager-loading the authors
     * in one pass to avoid N+1 queries.
     *
     * @return array<int, array{id: int, parent_id: int|null, author: string|null, body: string, is_deleted: bool, created_at: string|null}>
     */
    protected function commentsPayload(Project|Task $item): array
    {
        $item->loadMissing(['comments.user']);

        return $item->comments
            ->sortBy('id')
            ->map(static fn (Comment $comment): array => [
                'id' => $comment->id,
                'parent_id' => $comment->parent_id,
                'author' => $comment->user?->name,
                'body' => $comment->body,
                'is_deleted' => $comment->is_deleted,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * The shared output schema for the comments array exposed by the get tools.
     */
    protected function commentsSchema(JsonSchema $schema): Type
    {
        return $schema->array()->items($schema->object([
            'id' => $schema->integer()->description('The stable comment id.')->required(),
            'parent_id' => $schema->integer()->nullable()->description('The id of the comment this one replies to, or null for a top-level comment.'),
            'author' => $schema->string()->nullable()->description('The comment author\'s name; null if the author no longer exists.'),
            'body' => $schema->string()->description('The comment body as HTML; empty for a deleted comment kept to preserve its replies.')->required(),
            'is_deleted' => $schema->boolean()->description('Whether the comment was deleted (tombstoned); its body is then empty.')->required(),
            'created_at' => $schema->string()->description('When the comment was posted, as an ISO 8601 timestamp.'),
        ]))->description('The comments on this item, oldest first. Replies carry the parent_id of the comment they answer.')->required();
    }
}
