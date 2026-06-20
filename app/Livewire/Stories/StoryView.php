<?php

namespace App\Livewire\Stories;

use App\Actions\CreateTask;
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
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StoryView extends Component
{
    use HandlesAttachments;
    use ManagesDependencies;
    use ManagesTags;

    #[Locked]
    public string $shortName;

    #[Locked]
    public int $storyNumber;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    public int $priority;

    /** @var array<int, int> */
    public array $assigneeIds = [];

    // Create-task modal state.
    public bool $showTaskModal = false;

    public string $taskTitle = '';

    public string $taskDescription = '';

    public string $taskDueDate = '';

    public int $taskPriority;

    public string $taskStatus = Status::Planned->value;

    public function mount(string $short_name, int $story_number): void
    {
        $this->shortName = $short_name;
        $this->storyNumber = $story_number;

        $story = $this->story();
        $this->authorize('view', $story);

        $this->priority = $story->priority->value;
        $this->taskPriority = $story->priority->value;
        $this->assigneeIds = $story->assignees->pluck('id')->all();
    }

    #[Computed]
    public function story(): Story
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $story = $project->stories()
            ->with(['assignees', 'tags', 'tasks.assignees', 'project'])
            ->where('story_number', $this->storyNumber)
            ->firstOrFail();

        $this->authorize('view', $story);

        return $story;
    }

    protected function attachable(): Project|Story|Task
    {
        return $this->story();
    }

    protected function dependable(): Story|Task
    {
        return $this->story();
    }

    protected function taggable(): Story|Task
    {
        return $this->story();
    }

    protected function forgetTaggable(): void
    {
        unset($this->story);
    }

    /**
     * The project members available for assignment.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->story()->project->members()->orderBy('name')->get();
    }

    public function updatedPriority(string|int $value): void
    {
        $story = $this->story();
        $this->authorize('update', $story);

        $new = Priority::tryFrom((int) $value);

        if ($new === null || $story->priority === $new) {
            return;
        }

        $old = $story->priority;
        $story->priority = $new;
        $story->save();
        $story->recordActivity('priority_changed', 'priority', (string) $old->value, (string) $new->value);

        unset($this->story);
        Flux::toast(variant: 'success', text: __('Priority updated.'));
    }

    public function updatedAssigneeIds(): void
    {
        $story = $this->story();
        $this->authorize('update', $story);

        $changes = $story->assignees()->sync($this->assigneeIds);

        // Assigning a user automatically subscribes them to updates.
        if ($changes['attached'] !== []) {
            $story->subscribers()->syncWithoutDetaching($changes['attached']);
        }

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $story->recordActivity('assignee_changed', 'assignees');
        }

        unset($this->story);
    }

    public function edit(): void
    {
        $this->authorize('update', $this->story());

        $this->title = $this->story()->title;
        $this->description = (string) $this->story()->description;
        $this->dueDate = $this->story()->due_date?->format('Y-m-d') ?? '';
        $this->editing = true;
    }

    public function save(): void
    {
        $story = $this->story();

        $this->authorize('update', $story);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $story->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'due_date' => $validated['dueDate'] ?: null,
        ]);

        $this->editing = false;
        unset($this->story);

        Flux::toast(variant: 'success', text: __('Story updated.'));
    }

    /**
     * Archive this story, hiding it (and its tasks) from the board and overview.
     */
    public function archiveStory(): void
    {
        $story = $this->story();

        $this->authorize('update', $story);

        $story->archive();

        unset($this->story);

        Flux::toast(variant: 'success', text: __('Story archived.'));
    }

    /**
     * Restore this story from the archive.
     */
    public function unarchiveStory(): void
    {
        $story = $this->story();

        $this->authorize('update', $story);

        $story->unarchive();

        unset($this->story);

        Flux::toast(variant: 'success', text: __('Story restored.'));
    }

    public function openTaskModal(): void
    {
        $this->authorize('update', $this->story());

        $this->reset('taskTitle', 'taskDescription', 'taskDueDate');
        $this->taskStatus = Status::Planned->value;
        $this->taskPriority = $this->story()->priority->value;
        $this->showTaskModal = true;
    }

    public function createTask(): void
    {
        $story = $this->story();
        $this->authorize('update', $story);

        $validated = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string'],
            'taskPriority' => ['required', new Enum(Priority::class)],
            'taskDueDate' => ['nullable', 'date'],
            'taskStatus' => ['required', 'string', 'in:'.collect(Status::cases())->map->value->implode(',')],
        ]);

        app(CreateTask::class)->handle(
            $story,
            $validated['taskTitle'],
            $validated['taskDescription'] ?? null,
            Priority::from($validated['taskPriority']),
            Status::from($validated['taskStatus']),
            $validated['taskDueDate'],
        );

        $this->reset('taskTitle', 'taskDescription', 'taskDueDate', 'showTaskModal');
        unset($this->story);

        Flux::toast(variant: 'success', text: __('Task created.'));
    }
}
