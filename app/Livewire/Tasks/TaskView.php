<?php

namespace App\Livewire\Tasks;

use App\Concerns\HandlesAttachments;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TaskView extends Component
{
    use HandlesAttachments;

    public string $shortName;

    public int $storyNumber;

    public int $taskNumber;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $keywords = '';

    public string $status = Status::Planned->value;

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
        $this->assigneeIds = $task->assignees->pluck('id')->all();
    }

    #[Computed]
    public function task(): Task
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        return Task::query()
            ->with(['assignees', 'keywords', 'story.project'])
            ->whereHas('story', fn ($q) => $q
                ->where('project_id', $project->id)
                ->where('story_number', $this->storyNumber))
            ->where('task_number', $this->taskNumber)
            ->firstOrFail();
    }

    protected function attachable(): Project|Story|Task
    {
        return $this->task();
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
        $this->keywords = $task->keywordList();
        $this->editing = true;
    }

    public function save(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $task->update($validated);

        $changes = $task->syncKeywords($this->keywords);
        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $task->recordActivity('keywords_changed', 'keywords');
        }

        $this->editing = false;
        unset($this->task);

        Flux::toast(variant: 'success', text: __('Task updated.'));
    }
}
