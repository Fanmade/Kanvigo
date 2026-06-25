<?php

namespace App\Livewire\Comments;

use App\Concerns\HandlesAttachments;
use App\Concerns\ResolvesMorphSubject;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class CommentList extends Component
{
    use HandlesAttachments;
    use ResolvesMorphSubject;

    public const string COLLAPSED_PREFERENCE_KEY = 'comments_collapsed';

    /**
     * How many top-level comments are revealed per "show older" step (and the
     * initial window). Keeps the list — and the per-poll re-fetch — bounded on
     * busy items instead of loading the entire history every render.
     */
    public const int PER_PAGE = 10;

    public bool $collapsed = false;

    /**
     * The number of top-level comments currently shown, grown by {@see showMore()}.
     */
    public int $visible = self::PER_PAGE;

    public string $body = '';

    public ?int $replyingTo = null;

    public string $replyBody = '';

    public ?int $editingId = null;

    public string $editBody = '';

    public ?int $confirmingDelete = null;

    public string $deleteReason = '';

    public function mount(Project|Task $commentable): void
    {
        $this->initMorphSubject($commentable);

        $this->collapsed = (bool) Auth::user()->preference(self::COLLAPSED_PREFERENCE_KEY, false);
    }

    /**
     * Toggle the comments section and persist the state as a user preference.
     */
    public function toggleCollapsed(): void
    {
        $this->collapsed = ! $this->collapsed;

        Auth::user()->setPreference(self::COLLAPSED_PREFERENCE_KEY, $this->collapsed);
    }

    /**
     * Resolve the model the comments belong to.
     */
    #[Computed]
    public function commentable(): Project|Task
    {
        return $this->resolveMorphSubject();
    }

    /**
     * Inline images pasted into a comment editor attach to the commented-on item.
     */
    protected function attachable(): Project|Task
    {
        return $this->commentable();
    }

    /**
     * The endpoint the comment editor fetches @mention / #reference suggestions
     * from (a project comments against itself; a task against its project).
     */
    #[Computed]
    public function mentionablesUrl(): string
    {
        $commentable = $this->commentable();
        $project = $commentable instanceof Task ? $commentable->project : $commentable;

        return route('project.mentionables', $project);
    }

    /**
     * Top-level comments (newest first) with their replies eager-loaded.
     *
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->commentable()->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->latest()
            ->limit($this->visible)
            ->get();
    }

    /**
     * Count of top-level comments, used for the collapsed-state badge.
     */
    #[Computed]
    public function commentCount(): int
    {
        return $this->commentable()->comments()->whereNull('parent_id')->count();
    }

    /**
     * Whether older top-level comments remain beyond the current window.
     */
    #[Computed]
    public function hasMoreComments(): bool
    {
        return $this->commentCount() > $this->visible;
    }

    /**
     * Reveal the next page of older top-level comments.
     */
    public function showMore(): void
    {
        $this->visible += self::PER_PAGE;

        unset($this->comments, $this->hasMoreComments);
    }

    /**
     * Live-updates tick: pull in comments added by others. The task-page poll
     * that fires this already skips ticks while a comment editor is focused, so a
     * draft is never lost.
     */
    #[On('live-refresh')]
    public function liveRefresh(): void
    {
        unset($this->comments, $this->commentCount, $this->hasMoreComments);
    }

    public function addComment(): void
    {
        $validated = $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->storeComment($validated['body']);

        $this->reset('body');

        // Collapse the composer back to its input-styled trigger (see the blade).
        $this->dispatch('comment-added');
    }

    public function startReply(int $commentId): void
    {
        $this->reset('editingId', 'editBody', 'confirmingDelete', 'deleteReason');
        $this->replyingTo = $commentId;
        $this->replyBody = '';
        $this->resetValidation();
    }

    public function cancelReply(): void
    {
        $this->reset('replyingTo', 'replyBody');
    }

    public function startEdit(int $commentId): void
    {
        $comment = $this->commentable()->comments()->findOrFail($commentId);
        $this->authorize('update', $comment);

        $this->reset('replyingTo', 'replyBody', 'confirmingDelete', 'deleteReason');
        $this->editingId = $comment->id;
        $this->editBody = $comment->body;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editBody');
    }

    public function updateComment(): void
    {
        $comment = $this->commentable()->comments()->findOrFail($this->editingId);
        $this->authorize('update', $comment);

        $validated = $this->validate([
            'editBody' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update(['body' => $validated['editBody']]);

        $this->reset('editingId', 'editBody');
        unset($this->comments);
    }

    public function confirmDelete(int $commentId): void
    {
        $comment = $this->commentable()->comments()->findOrFail($commentId);
        $this->authorize('delete', $comment);

        $this->reset('replyingTo', 'replyBody', 'editingId', 'editBody');
        $this->confirmingDelete = $comment->id;
        $this->deleteReason = '';
    }

    public function cancelDelete(): void
    {
        $this->reset('confirmingDelete', 'deleteReason');
    }

    public function deleteComment(): void
    {
        $comment = $this->commentable()->comments()->findOrFail($this->confirmingDelete);
        $this->authorize('delete', $comment);

        if ($comment->replies()->exists()) {
            // Keep the row (so replies survive) but tombstone its content.
            $comment->forceFill([
                'is_deleted' => true,
                'body' => '',
                'delete_reason' => trim($this->deleteReason) ?: null,
            ])->save();
        } else {
            $comment->delete();
        }

        $this->reset('confirmingDelete', 'deleteReason');
        unset($this->comments, $this->commentCount, $this->hasMoreComments);
    }

    public function addReply(): void
    {
        $validated = $this->validate([
            'replyBody' => ['required', 'string', 'max:5000'],
        ]);

        $parent = $this->commentable()->comments()->findOrFail($this->replyingTo);

        // Keep threads one level deep: a reply to a reply attaches to the root.
        $this->storeComment($validated['replyBody'], $parent->parent_id ?? $parent->id);

        $this->reset('replyingTo', 'replyBody');
    }

    /**
     * Persist a comment (optionally as a reply) and log the activity.
     */
    protected function storeComment(string $body, ?int $parentId = null): void
    {
        $commentable = $this->commentable();

        // Anyone who can view the item (i.e. project members) may comment.
        $this->authorize('view', $commentable);

        $commentable->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
            'parent_id' => $parentId,
        ]);

        $commentable->recordActivity('commented');

        unset($this->comments, $this->commentCount, $this->hasMoreComments);
    }
}
