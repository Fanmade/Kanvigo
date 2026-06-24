<?php

namespace App\Git;

use App\Git;
use App\Models\Task;
use Database\Factories\Git\TaskGitLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The link between a task and its git refs: the deterministic branch reserved
 * when the task moves to In progress and, once a client opens one, its pull
 * request and merge state. Lives in the isolated {@see Git} domain so the
 * Task domain stays uncoupled from git.
 *
 * @property int $id
 * @property int $task_id
 * @property string $branch_name
 * @property string $base_branch
 * @property string|null $pr_url
 * @property int|null $pr_number
 * @property PrState $pr_state
 * @property string|null $merge_commit_sha
 * @property Carbon|null $opened_at
 * @property Carbon|null $merged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 */
#[Fillable(['branch_name', 'base_branch', 'pr_url', 'pr_number', 'pr_state', 'merge_commit_sha', 'opened_at', 'merged_at'])]
class TaskGitLink extends Model
{
    /** @use HasFactory<TaskGitLinkFactory> */
    use HasFactory;

    protected $table = 'task_git_links';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pr_number' => 'integer',
            'pr_state' => PrState::class,
            'opened_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    /**
     * The task this git link belongs to.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
