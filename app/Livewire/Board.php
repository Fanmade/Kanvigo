<?php

namespace App\Livewire;

use App\Concerns\BuildsKanbanColumns;
use App\Concerns\HasLiveUpdates;
use App\Concerns\PromptsParentClose;
use App\Enums\Status;
use App\Models\Task;
use App\Support\BlockedTasks;
use App\Support\BoardCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Board')]
class Board extends Component
{
    use BuildsKanbanColumns;
    use HasLiveUpdates;
    use PromptsParentClose;

    /**
     * Whether archived tasks are shown.
     */
    public bool $showArchived = false;

    /**
     * Per-column text search, keyed by `Status->value`. Each lane filters its
     * own cards by title or reference, independent of the others.
     *
     * @var array<string, string>
     */
    public array $columnSearch = [];

    /**
     * Every task across the user's accessible projects, ordered by project then
     * task number, with the data the cards need. Cached per user under the
     * combined project version, so an idle poll is a cheap version read + cache
     * hit rather than a full cross-project scan. Shared by {@see columns()} and
     * {@see blockedTaskIds()} so the board is fetched once, not twice.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $projectIds = Auth::user()->projects()->pluck('projects.id')->all();

        return BoardCache::remember(
            'board:user:'.Auth::id().':tasks:'.BoardCache::versionFor($projectIds),
            static fn (): Collection => Task::query()
                ->whereIn('project_id', $projectIds)
                ->with(['project', 'assignees', 'tags', 'taskType', 'ancestors'])
                ->get()
                ->sortBy(static fn (Task $task): string => sprintf(
                    '%s-%05d',
                    $task->project->short_name,
                    $task->task_number,
                ))
                ->values(),
        );
    }

    /**
     * Board columns for every task in the projects the user can access,
     * ordered by project then task so groups stay adjacent. Archived tasks are
     * hidden unless {@see $showArchived} is on.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function columns(): array
    {
        $tasks = $this->tasks();

        if (! $this->showArchived) {
            $tasks = $tasks->reject(static fn (Task $task): bool => $task->isArchived())->values();
        }

        return $this->buildColumns($tasks, $this->columnSearch);
    }

    /**
     * The ids of visible tasks that are blocked by an unfinished dependency.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function blockedTaskIds(): array
    {
        $projectIds = Auth::user()->projects()->pluck('projects.id')->all();

        return BoardCache::remember(
            'board:user:'.Auth::id().':blocked:'.BoardCache::versionFor($projectIds),
            fn (): array => BlockedTasks::ids($this->tasks()->pluck('id')->all()),
        );
    }

    /**
     * Move a task on the global board (authorization cascades to project access).
     */
    public function moveTask(int $taskId, string $status): void
    {
        $task = Task::with('project')->findOrFail($taskId);

        $result = $this->applyTaskMove($task, $status);

        unset($this->columns, $this->blockedTaskIds);

        if ($result !== null && ($new = Status::tryFrom($status)) !== null) {
            $this->maybePromptParentClose($result, $new);
        }
    }

    /**
     * Drag-and-drop placement: move and reorder a task on the global board.
     */
    public function reorderTask(int $taskId, string $status, ?int $beforeId, ?int $afterId): void
    {
        $task = Task::with('project')->findOrFail($taskId);

        $result = $this->applyTaskReorder($task, $status, $beforeId, $afterId);

        unset($this->columns, $this->blockedTaskIds);

        if ($result !== null && ($new = Status::tryFrom($status)) !== null) {
            $this->maybePromptParentClose($result, $new);
        }
    }

    /**
     * Archive a task, removing it from the board.
     */
    public function archiveTask(int $taskId): void
    {
        $task = Task::with('project')->findOrFail($taskId);

        $this->applyTaskArchive($task);

        unset($this->columns, $this->blockedTaskIds);
    }

    /**
     * Restore a task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $task = Task::with('project')->findOrFail($taskId);

        $this->applyTaskUnarchive($task);

        unset($this->columns, $this->blockedTaskIds);
    }
}
