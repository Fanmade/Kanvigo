<?php

namespace App\Models;

use App\Actions\CreateTask;
use App\Concerns\Archivable;
use App\Concerns\Cancellable;
use App\Concerns\HasAttachments;
use App\Concerns\HasComments;
use App\Concerns\HasDependencies;
use App\Concerns\HasMentions;
use App\Concerns\HasReferences;
use App\Concerns\HasScopedNumber;
use App\Concerns\HasSubscribers;
use App\Concerns\HasTags;
use App\Concerns\LogsActivity;
use App\Concerns\Nestable;
use App\Concerns\PrunesInlineAttachments;
use App\Concerns\SanitizesRichText;
use App\Contracts\Dependable;
use App\Contracts\Mentionable;
use App\Contracts\Referenceable;
use App\Contracts\Subscribable;
use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\Status;
use App\Support\BoardCache;
use App\Support\TaskProgress;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $parent_id
 * @property int $task_number
 * @property string $title
 * @property string|null $description
 * @property Priority $priority
 * @property int|null $task_type_id
 * @property Status $status
 * @property float $position
 * @property Carbon|null $due_date
 * @property Carbon|null $archived_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $canceled_at
 * @property CancelReason|null $cancel_reason
 * @property string|null $cancel_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $reference
 * @property-read Project $project
 * @property-read TaskType|null $taskType
 * @property-read Task|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Task> $children
 */
#[Fillable(['title', 'description', 'priority', 'due_date'])]
class Task extends Model implements Dependable, Mentionable, Referenceable, Subscribable
{
    /** @use HasFactory<TaskFactory> */
    use Archivable, Cancellable, HasAttachments, HasComments, HasDependencies, HasFactory, HasMentions, HasReferences, HasScopedNumber, HasSubscribers, HasTags, LogsActivity, Nestable, PrunesInlineAttachments, SanitizesRichText;

    protected string $scopedNumberColumn = 'task_number';

    /**
     * A new subtask inherits its parent's priority unless one is set explicitly.
     * The owning project is set by the caller (factory, {@see CreateTask}
     * or the `childOf` factory state) before save, since the per-project number
     * ({@see scopedNumberQuery()}) is derived from it.
     */
    protected static function booted(): void
    {
        static::creating(static function (Task $task): void {
            // priority is non-null once settled, but unset before save — guard the transient null.
            // @phpstan-ignore identical.alwaysFalse
            if ($task->priority === null) {
                $parent = $task->parent_id !== null ? static::find($task->parent_id) : null;
                $task->priority = $parent instanceof self ? $parent->priority : Priority::default();
            }

            // New tasks land at the bottom of the board (largest position).
            if (! $task->isDirty('position')) {
                $task->position = (static::max('position') ?? 0) + 1;
            }
        });

        // Track when a task entered Done so auto-archiving can measure how long it
        // has sat there. Cleared whenever it leaves Done (including on cancel).
        static::saving(static function (Task $task): void {
            if ($task->status === Status::Done) {
                $task->completed_at ??= Carbon::now();
            } else {
                $task->completed_at = null;
            }
        });

        // Any task row change (status, position, title, archived, create, …)
        // invalidates the cached boards showing its project. Pivot changes
        // (tags/assignees/dependencies) bump via the audit ActivityLogSink instead.
        static::saved(static fn (Task $task) => BoardCache::touch($task->project_id));
        static::deleted(static fn (Task $task) => BoardCache::touch($task->project_id));
    }

    /**
     * Field edits audited on every write path (UI, MCP, REST). Priority is
     * feed-worthy and shows up as activity; the rest are audit-only.
     *
     * @return array<string, string>
     */
    protected function auditedFieldChanges(): array
    {
        return [
            'title' => 'title_changed',
            'description' => 'description_changed',
            'due_date' => 'due_date_changed',
            'priority' => 'priority_changed',
        ];
    }

    public function inlineAttachmentOwner(): Project|Task
    {
        return $this;
    }

    public function inlineDocumentColumn(): string
    {
        return 'description';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => Status::class,
            'position' => 'double',
            'due_date' => 'date',
            'archived_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_reason' => CancelReason::class,
        ];
    }

    /**
     * Task numbers run as a single sequence per project, so the reference is flat
     * (e.g. "ABC-42") and survives re-parenting.
     *
     * @return Builder<static>
     */
    public function scopedNumberQuery(): Builder
    {
        return static::query()->where('project_id', $this->project_id);
    }

    /**
     * The owning project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The task's optional type (e.g. Feature, Bug), or null when untyped.
     *
     * @return BelongsTo<TaskType, $this>
     */
    public function taskType(): BelongsTo
    {
        return $this->belongsTo(TaskType::class);
    }

    /**
     * The users assigned to this task.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * A task's @mentions are limited to its project's members.
     *
     * @return list<int>
     */
    public function mentionableUserIds(): array
    {
        return array_values(array_map('intval', $this->project->members()->pluck('users.id')->all()));
    }

    /**
     * A task is its own mention subject.
     */
    protected function mentionSubject(): Project|Task
    {
        return $this;
    }

    /**
     * A task update also reaches the project's subscribers.
     *
     * @return Collection<int, User>
     */
    public function notificationAudience(): Collection
    {
        return $this->subscribers()->get()
            ->merge($this->project->subscribers()->get())
            ->unique('id');
    }

    /**
     * Whether the task is done. Drives dependency resolution: a task stops
     * blocking the work that depends on it once it is done.
     */
    public function isComplete(): bool
    {
        return $this->status === Status::Done;
    }

    /**
     * Completeness rolled up from this task's subtree: how many of its descendant
     * tasks are done out of the total. A leaf task has no subtree and so reports an
     * empty progress. Prefers an already-loaded {@see descendants()} relation,
     * falling back to two count queries.
     */
    public function progress(): TaskProgress
    {
        if ($this->relationLoaded('descendants')) {
            return new TaskProgress(
                done: $this->descendants->where('status', Status::Done)->count(),
                total: $this->descendants->count(),
            );
        }

        return new TaskProgress(
            done: $this->descendants()->where('status', Status::Done)->count(),
            total: $this->descendants()->count(),
        );
    }

    /**
     * The task's open (non-terminal) descendants across the whole subtree — the
     * tasks a Done/Canceled cascade would reach. Prefers an already-loaded
     * {@see descendants()} relation, falling back to a query.
     *
     * @return Collection<int, static>
     */
    public function openDescendants(): Collection
    {
        $descendants = $this->relationLoaded('descendants')
            ? $this->descendants
            : $this->descendants()->get();

        return $descendants
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->values()
            ->toBase();
    }

    /**
     * How many open (non-terminal) descendants the task has across its subtree.
     */
    public function openSubtaskCount(): int
    {
        return $this->openDescendants()->count();
    }

    /**
     * How many of the task's direct children are still open. Prefers an
     * already-loaded {@see children()} relation, falling back to a query.
     */
    public function openChildCount(): int
    {
        $children = $this->relationLoaded('children')
            ? $this->children
            : $this->children()->get();

        return $children
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->count();
    }

    /**
     * The public, flat per-project reference, e.g. "ABC-42".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->project->short_name.'-'.$this->task_number);
    }
}
