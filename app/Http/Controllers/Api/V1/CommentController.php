<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    use ResolvesApiReferences;

    /**
     * List a project's comments (top-level, newest first, with replies).
     */
    public function indexForProject(string $short_name): AnonymousResourceCollection
    {
        return $this->listComments($this->resolveProjectOr404($short_name));
    }

    /**
     * List a task's comments (top-level, newest first, with replies).
     */
    public function indexForTask(string $reference): AnonymousResourceCollection
    {
        return $this->listComments($this->resolveTaskOr404($reference));
    }

    /**
     * Add a comment to a project, by its short name.
     */
    public function storeOnProject(Request $request, string $short_name): CommentResource
    {
        $project = $this->resolveProjectOr404($short_name);
        abort_if(Auth::user()->cannot('create-comment', $project), 403);

        return $this->addComment($request, $project);
    }

    /**
     * Add a comment to a task, by its reference (e.g. "PROJ-42").
     */
    public function storeOnTask(Request $request, string $reference): CommentResource
    {
        $task = $this->resolveTaskOr404($reference);
        abort_if(Auth::user()->cannot('create-comment', $task->project), 403);

        return $this->addComment($request, $task);
    }

    /**
     * Edit one of the caller's own comments.
     */
    public function update(Request $request, int $comment): CommentResource
    {
        $model = $this->authorizedComment($comment, 'update');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $model->update(['body' => $validated['body']]);

        return new CommentResource($model->load('user'));
    }

    /**
     * Delete one of the caller's own comments. A comment that has replies is kept
     * as a tombstone (so the thread survives) rather than removed outright.
     */
    public function destroy(Request $request, int $comment): JsonResponse
    {
        $model = $this->authorizedComment($comment, 'delete');

        $reason = $request->validate([
            'delete_reason' => ['nullable', 'string', 'max:255'],
        ])['delete_reason'] ?? null;

        if ($model->replies()->exists()) {
            $model->forceFill([
                'is_deleted' => true,
                'body' => '',
                'delete_reason' => trim((string) $reason) ?: null,
            ])->save();
        } else {
            $model->delete();
        }

        return response()->json(status: 204);
    }

    /**
     * Paginate the top-level comments of a commentable, with their authors and
     * one level of replies.
     */
    protected function listComments(Project|Task $commentable): AnonymousResourceCollection
    {
        $comments = $commentable->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->latest()
            ->paginate();

        return CommentResource::collection($comments);
    }

    /**
     * Create the comment on the resolved commentable, optionally as a reply, and
     * record the activity.
     */
    protected function addComment(Request $request, Project|Task $commentable): CommentResource
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parentId = null;

        if (! empty($validated['parent_id'])) {
            $parent = $commentable->comments()->whereKey($validated['parent_id'])->first();

            if ($parent === null) {
                throw ValidationException::withMessages([
                    'parent_id' => __('The parent comment does not belong to this item.'),
                ]);
            }

            // Keep threads one level deep: a reply to a reply attaches to the root.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        /** @var Comment $comment */
        $comment = $commentable->comments()->create([
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'parent_id' => $parentId,
        ]);

        $commentable->recordActivity('commented');

        return new CommentResource($comment->load('user'));
    }

    /**
     * Resolve a comment the caller may act on: it must exist, the caller must be
     * able to view the item it is on (else 404), and the policy ability must pass
     * (owner-only edit/delete, else 403).
     */
    protected function authorizedComment(int $id, string $ability): Comment
    {
        $comment = Comment::find($id);

        abort_if($comment === null || Auth::user()->cannot('view', $comment->commentable), 404);
        abort_if(Auth::user()->cannot($ability, $comment), 403);

        return $comment;
    }
}
