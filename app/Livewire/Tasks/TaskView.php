<?php

namespace App\Livewire\Tasks;

use App\Concerns\HandlesAttachments;
use App\Concerns\ManagesDependencies;
use App\Concerns\ManagesTags;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class TaskView extends Component
{
    use HandlesAttachments;
    use ManagesDependencies;
    use ManagesTags;

    #[Locked]
    public string $shortName;

    #[Locked]
    public int $storyNumber;

    #[Locked]
    public int $taskNumber;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    public string $status = Status::Planned->value;

    public int $priority;

    /** @var array<int, int> */
    public array $assigneeIds = [];

    public function mount(string $short_name, int $story_number, int $task_number): void
    {
        $this->shortName = $short_name;
        $this->storyNumber = $story_number;
        $this->taskNumber = $task_number;

        $task = $this->task();
        $this->authorize('view', $task);

        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->assigneeIds = $task->assignees->pluck('id')->all();
    }

    #[Computed]
    public function task(): Task
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $task = Task::query()
            ->with(['assignees', 'tags', 'story.project'])
            ->whereHas('story', fn ($q) => $q
                ->where('project_id', $project->id)
                ->where('story_number', $this->storyNumber))
            ->where('task_number', $this->taskNumber)
            ->firstOrFail();

        $this->authorize('view', $task);

        return $task;
    }

    protected function attachable(): Project|Story|Task
    {
        return $this->task();
    }

    protected function dependable(): Story|Task
    {
        return $this->task();
    }

    protected function taggable(): Story|Task
    {
        return $this->task();
    }

    protected function forgetTaggable(): void
    {
        unset($this->task);
    }

    /**
     * The project members available for assignment.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->task()->story->project->members()->orderBy('name')->get();
    }

    public function updatedStatus(string $value): void
    {
        $task = $this->task();
        $this->authorize('updateStatus', $task);

        $new = Status::tryFrom($value);

        if ($new === null || $task->status === $new) {
            return;
        }

        $old = $task->status;
        $task->status = $new;
        $task->save();
        $task->recordActivity('status_changed', 'status', $old->value, $new->value);

        unset($this->task);
        Flux::toast(variant: 'success', text: __('Status updated.'));
    }

    public function updatedPriority(string|int $value): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $new = Priority::tryFrom((int) $value);

        if ($new === null || $task->priority === $new) {
            return;
        }

        $old = $task->priority;
        $task->priority = $new;
        $task->save();
        $task->recordActivity('priority_changed', 'priority', (string) $old->value, (string) $new->value);

        unset($this->task);
        Flux::toast(variant: 'success', text: __('Priority updated.'));
    }

    public function updatedAssigneeIds(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $changes = $task->assignees()->sync($this->assigneeIds);

        // Assigning a user automatically subscribes them to updates.
        if ($changes['attached'] !== []) {
            $task->subscribers()->syncWithoutDetaching($changes['attached']);
        }

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $task->recordActivity('assignee_changed', 'assignees');
        }

        unset($this->task);
    }

    public function edit(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->dueDate = $task->due_date?->format('Y-m-d') ?? '';
        $this->editing = true;
    }

    public function save(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $task->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'due_date' => $validated['dueDate'] ?: null,
        ]);

        $this->editing = false;
        unset($this->task);

        Flux::toast(variant: 'success', text: __('Task updated.'));
    }
}
