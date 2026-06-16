<?php

namespace App\Livewire\Comments;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CommentList extends Component
{
    public const string COLLAPSED_PREFERENCE_KEY = 'comments_collapsed';

    public string $commentableType;

    public int $commentableId;

    public bool $collapsed = false;

    public string $body = '';

    public ?int $replyingTo = null;

    public string $replyBody = '';

    public ?int $editingId = null;

    public string $editBody = '';

    public ?int $confirmingDelete = null;

    public string $deleteReason = '';

    public function mount(Project|Story|Task $commentable): void
    {
        $this->commentableType = $commentable->getMorphClass();
        $this->commentableId = $commentable->getKey();

        $this->authorize('view', $commentable);

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
    public function commentable(): Project|Story|Task
    {
        $class = Relation::getMorphedModel($this->commentableType) ?? $this->commentableType;

        return match ($class) {
            Project::class => Project::findOrFail($this->commentableId),
            Story::class => Story::findOrFail($this->commentableId),
            Task::class => Task::findOrFail($this->commentableId),
            default => abort(404),
        };
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

    public function addComment(): void
    {
        $validated = $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->storeComment($validated['body']);

        $this->reset('body');
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
        unset($this->comments);
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

        unset($this->comments);
    }
}
