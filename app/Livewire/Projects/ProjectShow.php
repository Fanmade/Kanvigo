<?php

namespace App\Livewire\Projects;

use App\Actions\CreateTask;
use App\Concerns\HandlesAttachments;
use App\Models\Project;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProjectShow extends Component
{
    use HandlesAttachments;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    public string $title = '';

    public string $short_name = '';

    public string $description = '';

    public bool $showTaskModal = false;

    public bool $showArchived = false;

    public string $taskTitle = '';

    public string $taskDescription = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project());
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)
            ->with(['rootTasks.tags', 'rootTasks.assignees', 'rootTasks.descendants'])
            ->firstOrFail();

        $this->authorize('view', $project);

        return $project;
    }

    protected function attachable(): Project|Task
    {
        return $this->project();
    }

    /**
     * Active (non-archived) top-level tasks that are still in progress.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function openTasks(): Collection
    {
        return $this->project()->rootTasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->values();
    }

    /**
     * Active (non-archived) top-level tasks that are done or canceled.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function completedTasks(): Collection
    {
        return $this->project()->rootTasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->filter(static fn (Task $task): bool => $task->status->isTerminal())
            ->values();
    }

    /**
     * Archived top-level tasks, surfaced only behind the "Show archived" toggle.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function archivedTasks(): Collection
    {
        return $this->project()->rootTasks
            ->filter(static fn (Task $task): bool => $task->isArchived())
            ->values();
    }

    /**
     * Archive a top-level task, removing it from the project overview and board.
     */
    public function archiveTask(int $taskId): void
    {
        $task = $this->project()->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('update', $task);

        $task->archive();

        unset($this->project);

        Flux::toast(variant: 'success', text: __('Task archived.'));
    }

    /**
     * Restore a top-level task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $task = $this->project()->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('update', $task);

        $task->unarchive();

        unset($this->project);

        Flux::toast(variant: 'success', text: __('Task restored.'));
    }

    public function edit(): void
    {
        $this->authorize('update', $this->project());

        $this->title = $this->project()->title;
        $this->short_name = $this->project()->short_name;
        $this->description = (string) $this->project()->description;
        $this->editing = true;
    }

    public function save(): void
    {
        $project = $this->project();

        $this->authorize('update', $project);

        $this->short_name = strtoupper($this->short_name);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_name' => [
                'required', 'string', 'min:2', 'max:4', 'alpha', 'uppercase',
                Rule::notIn(['WWW', 'API', 'APP', 'FTP']),
                Rule::unique('projects', 'short_name')->ignore($project->id),
            ],
            'description' => ['nullable', 'string'],
        ]);

        $shortNameChanged = $project->short_name !== $validated['short_name'];

        $project->update($validated);

        $this->editing = false;
        unset($this->project);

        Flux::toast(variant: 'success', text: __('Project updated.'));

        // The short name is the route key, so move to the new URL when it changes.
        if ($shortNameChanged) {
            $this->shortName = $validated['short_name'];
            $this->redirectRoute('project.show', ['short_name' => $validated['short_name']], navigate: true);
        }
    }

    public function createTask(): void
    {
        $this->authorize('update', $this->project());

        $validated = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string'],
        ]);

        app(CreateTask::class)->handle(
            $this->project(),
            $validated['taskTitle'],
            $validated['taskDescription'] ?? null,
        );

        $this->reset('taskTitle', 'taskDescription', 'showTaskModal');
        unset($this->project);

        Flux::toast(variant: 'success', text: __('Task created.'));
    }
}
