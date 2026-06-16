<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * A user may edit only their own comments.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id && ! $comment->is_deleted;
    }

    /**
     * A user may delete only their own comments.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id && ! $comment->is_deleted;
    }
}
