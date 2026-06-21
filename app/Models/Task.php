<?php

namespace App\Models;

use App\Actions\CreateTask;
use App\Concerns\Archivable;
use App\Concerns\HasAttachments;
use App\Concerns\HasComments;
use App\Concerns\HasDependencies;
use App\Concerns\HasScopedNumber;
use App\Concerns\HasSubscribers;
use App\Concerns\HasTags;
use App\Concerns\LogsActivity;
use App\Concerns\Nestable;
use App\Contracts\Dependable;
use App\Contracts\Subscribable;
use App\Enums\Priority;
use App\Enums\Status;
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
 * @property Status $status
 * @property float $position
 * @property Carbon|null $due_date
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $reference
 * @property-read Project $project
 * @property-read Task|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Task> $children
 */
#[Fillable(['title', 'description', 'priority', 'due_date'])]
class Task extends Model implements Dependable, Subscribable
{
    /** @use HasFactory<TaskFactory> */
    use Archivable, HasAttachments, HasComments, HasDependencies, HasFactory, HasScopedNumber, HasSubscribers, HasTags, LogsActivity, Nestable;

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
     * The users assigned to this task.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
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
     * The public, flat per-project reference, e.g. "ABC-42".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->project->short_name.'-'.$this->task_number);
    }
}
