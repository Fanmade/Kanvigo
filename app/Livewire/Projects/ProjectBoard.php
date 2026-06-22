<?php

namespace App\Livewire\Projects;

use App\Concerns\BuildsKanbanColumns;
use App\Concerns\PromptsParentClose;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Support\BlockedTasks;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectBoard extends Component
{
    use BuildsKanbanColumns;
    use PromptsParentClose;

    #[Locked]
    public string $shortName;

    // Board filters.
    public ?int $priorityFilter = null;

    public bool $showArchived = false;

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project());
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('view', $project);

        return $project;
    }

    /**
     * Every task in the project, with the data the cards need.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $project = $this->project();

        return $project->tasks()->with(['assignees', 'tags'])->get()
            ->each(static fn (Task $task) => $task->setRelation('project', $project));
    }

    /**
     * The board columns for this project's tasks. Archived tasks are hidden unless
     * {@see $showArchived} is on.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function columns(): array
    {
        $tasks = $this->tasks();

        if (! $this->showArchived) {
            $tasks = $tasks->reject(static fn (Task $task): bool => $task->isArchived());
        }

        if ($this->priorityFilter) {
            $tasks = $tasks->filter(fn (Task $task): bool => $task->priority->value === $this->priorityFilter);
        }

        return $this->buildColumns($tasks);
    }

    /**
     * The ids of this project's tasks that are blocked by an unfinished dependency.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function blockedTaskIds(): array
    {
        return BlockedTasks::ids($this->tasks()->pluck('id')->all());
    }

    /**
     * Move a task to a new status column (drag-and-drop drop handler).
     */
    public function moveTask(int $taskId, string $status): void
    {
        $result = $this->applyTaskMove($this->resolveProjectTask($taskId), $status);

        unset($this->tasks, $this->columns, $this->blockedTaskIds);

        if ($result !== null && ($new = Status::tryFrom($status)) !== null) {
            $this->maybePromptParentClose($result, $new);
        }
    }

    /**
     * Drag-and-drop placement: move and reorder a task within this project's board.
     */
    public function reorderTask(int $taskId, string $status, ?int $beforeId, ?int $afterId): void
    {
        $result = $this->applyTaskReorder($this->resolveProjectTask($taskId), $status, $beforeId, $afterId);

        unset($this->tasks, $this->columns, $this->blockedTaskIds);

        if ($result !== null && ($new = Status::tryFrom($status)) !== null) {
            $this->maybePromptParentClose($result, $new);
        }
    }

    /**
     * Archive a task, removing it from this project's board.
     */
    public function archiveTask(int $taskId): void
    {
        $this->applyTaskArchive($this->resolveProjectTask($taskId));

        unset($this->tasks, $this->columns, $this->blockedTaskIds);
    }

    /**
     * Restore a task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $this->applyTaskUnarchive($this->resolveProjectTask($taskId));

        unset($this->tasks, $this->columns, $this->blockedTaskIds);
    }

    /**
     * Refresh the board after a task is created through the shared create dialog.
     */
    #[On('task-created')]
    public function refreshAfterCreate(): void
    {
        unset($this->tasks, $this->columns, $this->blockedTaskIds);
    }

    /**
     * Resolve a task that belongs to this board's project, or 404.
     */
    protected function resolveProjectTask(int $taskId): Task
    {
        $task = Task::findOrFail($taskId);

        abort_unless($task->project_id === $this->project()->id, 404);

        return $task;
    }
}
