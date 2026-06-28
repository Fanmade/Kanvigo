<?php

namespace App\Livewire\Comments;

use App\Concerns\HandlesAttachments;
use App\Concerns\ResolvesMorphSubject;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Activity-log references (e.g. "KAN-42-log-2") the in-progress comment points
     * at, added via the "Discuss" action on a feed entry. Attached to the comment
     * when it is posted.
     *
     * @var list<string>
     */
    public array $referencedActivities = [];

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
        return route('project.mentionables', $this->project());
    }

    /**
     * The project the comments live in (a task comments belong to its project),
     * threaded to rendered comment bodies so each @mention link carries it for
     * the hovercard's "role in this project".
     */
    #[Computed]
    public function project(): Project
    {
        $commentable = $this->commentable();

        return $commentable instanceof Task ? $commentable->project : $commentable;
    }

    /**
     * Top-level comments (newest first) with their replies eager-loaded.
     *
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function comments(): Collection
    {
        $comments = $this->commentable()->comments()
            ->whereNull('parent_id')
            ->with([
                'user',
                'activities.user', 'activities.subject',
                'replies.user', 'replies.activities.user', 'replies.activities.subject',
            ])
            ->latest()
            ->limit($this->visible)
            ->get();

        // Referenced entries are always task-subject, and each card deep-links via
        // the task's project short name — eager-load the project on the referenced
        // task subjects so the cards don't N+1 as the comment list grows.
        $referencedTasks = $comments
            ->flatMap(static fn (Comment $comment): array => [$comment, ...$comment->replies->all()])
            ->flatMap(static fn (Comment $comment): Collection => $comment->activities)
            ->map(static fn (Activity $activity): ?Model => $activity->subject)
            ->filter(static fn (?Model $subject): bool => $subject instanceof Task);

        (new Collection($referencedTasks))->loadMissing('project');

        return $comments;
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

    /**
     * Reference an activity-log entry from the composer, triggered by the
     * "Discuss" action on a feed entry. Opens the comments section and composer,
     * adding the reference (deduplicated) so it is attached when posted. Unknown
     * references are ignored.
     */
    #[On('discuss-activity')]
    public function discussActivity(string $reference): void
    {
        if (ReferenceResolver::activity($reference) === null) {
            return;
        }

        if (! in_array($reference, $this->referencedActivities, true)) {
            $this->referencedActivities[] = $reference;
            unset($this->referencedActivityEntries);
        }

        $this->collapsed = false;

        // Open and focus the (Alpine-collapsed) composer in the blade.
        $this->dispatch('open-composer');
    }

    /**
     * Drop a referenced entry from the composer before the comment is posted.
     */
    public function removeReference(string $reference): void
    {
        $this->referencedActivities = array_values(
            array_filter($this->referencedActivities, static fn (string $r): bool => $r !== $reference)
        );

        unset($this->referencedActivityEntries);
    }

    /**
     * Resolve the composer's referenced activities to their models (skipping any
     * that no longer exist) for the preview cards.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function referencedActivityEntries(): Collection
    {
        $entries = array_filter(array_map(
            static fn (string $reference): ?Activity => ReferenceResolver::activity($reference),
            $this->referencedActivities,
        ));

        return new Collection(array_values($entries));
    }

    public function addComment(): void
    {
        $validated = $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $this->storeComment($validated['body']);
        $this->attachReferencedActivities($comment);

        $this->reset('body', 'referencedActivities');
        unset($this->referencedActivityEntries);

        // Collapse the composer back to its input-styled trigger (see the blade).
        $this->dispatch('comment-added');
    }

    /**
     * Attach the composer's referenced activity entries to a freshly posted
     * comment (ignoring any that have since been deleted).
     */
    protected function attachReferencedActivities(Comment $comment): void
    {
        $ids = $this->referencedActivityEntries()->modelKeys();

        if ($ids !== []) {
            $comment->activities()->attach($ids);
        }
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

        $reason = trim($this->deleteReason) ?: null;

        if ($comment->replies()->exists()) {
            // Keep the row (so replies survive) but tombstone its content.
            $comment->forceFill([
                'is_deleted' => true,
                'body' => '',
                'delete_reason' => $reason,
            ])->save();
        } else {
            $comment->delete();
        }

        // Record the removal in the audit trail (with the reason, if given), the
        // same way posting a comment logs a 'commented' entry.
        $this->commentable()->recordActivity('comment_deleted', null, null, $reason);

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
    protected function storeComment(string $body, ?int $parentId = null): Comment
    {
        $commentable = $this->commentable();
        $project = $commentable instanceof Task ? $commentable->project : $commentable;

        $this->authorize('create-comment', $project);

        $comment = $commentable->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
            'parent_id' => $parentId,
        ]);

        $commentable->recordActivity('commented');

        unset($this->comments, $this->commentCount, $this->hasMoreComments);

        return $comment;
    }
}
