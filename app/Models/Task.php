<?php

namespace App\Models;

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
 * @property int $story_id
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
 * @property-read Story $story
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
     * A new task inherits its parent story's priority unless one is set explicitly.
     */
    protected static function booted(): void
    {
        static::creating(static function (Task $task): void {
            // priority is non-null once settled, but unset before save — guard the transient null.
            // @phpstan-ignore identical.alwaysFalse
            if ($task->priority === null) {
                // @phpstan-ignore nullCoalesce.expr
                $task->priority = $task->story?->priority ?? Priority::default();
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
     * @return Builder<static>
     */
    public function scopedNumberQuery(): Builder
    {
        return static::query()->where('story_id', $this->story_id);
    }

    /**
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
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
     * The owning project, resolved through the story.
     */
    public function project(): ?Project
    {
        return $this->story->project;
    }

    /**
     * A task update also reaches the story's and project's subscribers.
     *
     * @return Collection<int, User>
     */
    public function notificationAudience(): Collection
    {
        return $this->subscribers()->get()
            ->merge($this->story->subscribers()->get())
            ->merge($this->story->project->subscribers()->get())
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
     * The public reference, e.g. "ABC1-3".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->story->reference.'-'.$this->task_number);
    }
}
