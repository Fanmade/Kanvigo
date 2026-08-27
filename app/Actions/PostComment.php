<?php

namespace App\Actions;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Support\Facades\Audit;
use Illuminate\Support\Facades\Auth;

/**
 * Post a comment on a project or task as the current user, recording the
 * `commented` activity that goes with it. Shared by the item's comment list and
 * the quick reply on the "Waiting on" page, so a comment written from either
 * place subscribes its author, clears a wait and shows up in the feed the same
 * way (the model hooks on {@see Comment} do the first two).
 */
class PostComment
{
    /**
     * @param  int|null  $parentId  the comment being replied to, or null for a new thread
     */
    public function handle(Project|Task $commentable, string $body, ?int $parentId = null): Comment
    {
        $comment = $commentable->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
            'parent_id' => $parentId,
        ]);

        Audit::record($commentable->contentAuditEvent('commented'));

        return $comment;
    }
}
