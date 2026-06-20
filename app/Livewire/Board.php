<?php

namespace App\Livewire;

use App\Concerns\BuildsKanbanColumns;
use App\Models\Task;
use App\Support\BlockedTasks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Board')]
class Board extends Component
{
    use BuildsKanbanColumns;

    /**
     * Whether archived tasks (and tasks of archived stories) are shown.
     */
    public bool $showArchived = false;

    /**
     * Board columns for every task in the projects the user can access,
     * ordered by project, story, then task so groups stay adjacent. Archived
     * tasks, and tasks belonging to an archived story, are hidden unless
     * {@see $showArchived} is on.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function columns(): array
    {
        $projectIds = Auth::user()->projects()->pluck('projects.id');
        $showArchived = $this->showArchived;

        $tasks = Task::query()
            ->whereHas('story', static function ($query) use ($projectIds, $showArchived): void {
                $query->whereIn('project_id', $projectIds);

                if (! $showArchived) {
                    $query->whereNull('archived_at');
                }
            })
            ->when(! $showArchived, static fn ($query) => $query->whereNull('archived_at'))
            ->with(['story.project', 'assignees', 'tags'])
            ->get()
            ->sortBy(static fn (Task $task) => sprintf(
                '%s-%05d',
                $task->story->project->short_name,
                $task->task_number,
            ))
            ->values();

        return $this->buildColumns($tasks);
    }

    /**
     * The ids of visible tasks that are blocked by an unfinished dependency.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function blockedTaskIds(): array
    {
        $projectIds = Auth::user()->projects()->pluck('projects.id');

        $taskIds = Task::query()
            ->whereHas('story', static fn ($query) => $query->whereIn('project_id', $projectIds))
            ->pluck('id')
            ->all();

        return BlockedTasks::ids($taskIds);
    }

    /**
     * Move a task on the global board (authorization cascades to project access).
     */
    public function moveTask(int $taskId, string $status): void
    {
        $task = Task::with('story.project')->findOrFail($taskId);

        $this->applyTaskMove($task, $status);

        unset($this->columns, $this->blockedTaskIds);
    }

    /**
     * Drag-and-drop placement: move and reorder a task on the global board.
     */
    public function reorderTask(int $taskId, string $status, ?int $beforeId, ?int $afterId): void
    {
        $task = Task::with('story.project')->findOrFail($taskId);

        $this->applyTaskReorder($task, $status, $beforeId, $afterId);

        unset($this->columns, $this->blockedTaskIds);
    }

    /**
     * Archive a task, removing it from the board.
     */
    public function archiveTask(int $taskId): void
    {
        $task = Task::with('story.project')->findOrFail($taskId);

        $this->applyTaskArchive($task);

        unset($this->columns, $this->blockedTaskIds);
    }

    /**
     * Restore a task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $task = Task::with('story.project')->findOrFail($taskId);

        $this->applyTaskUnarchive($task);

        unset($this->columns, $this->blockedTaskIds);
    }
}
