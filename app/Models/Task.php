<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\HasComments;
use App\Concerns\HasKeywords;
use App\Concerns\HasScopedNumber;
use App\Concerns\HasSubscribers;
use App\Concerns\LogsActivity;
use App\Contracts\Subscribable;
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
 * @property int $task_number
 * @property string $title
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $reference
 * @property-read Story $story
 */
#[Fillable(['title', 'description'])]
class Task extends Model implements Subscribable
{
    /** @use HasFactory<TaskFactory> */
    use HasAttachments, HasComments, HasFactory, HasKeywords, HasScopedNumber, HasSubscribers, LogsActivity;

    protected string $scopedNumberColumn = 'task_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
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
     * The public reference, e.g. "ABC1-3".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->story->reference.'-'.$this->task_number);
    }
}
