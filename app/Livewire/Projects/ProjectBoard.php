<?php

namespace App\Livewire\Projects;

use App\Concerns\BuildsKanbanColumns;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectBoard extends Component
{
    use BuildsKanbanColumns;

    public string $shortName;

    // Board filters.
    public ?int $priorityFilter = null;

    // Create-story modal state.
    public bool $showStoryModal = false;

    public string $storyTitle = '';

    public string $storyDescription = '';

    public string $storyDueDate = '';

    public int $storyPriority;

    // Create-task modal state.
    public bool $showTaskModal = false;

    public ?int $taskStoryId = null;

    public string $taskTitle = '';

    public string $taskDescription = '';

    public string $taskDueDate = '';

    public string $taskStatus = Status::Planned->value;

    public int $taskPriority;

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;
        $this->storyPriority = Priority::default()->value;
        $this->taskPriority = Priority::default()->value;

        $this->authorize('view', $this->project());
    }

    #[Computed]
    public function project(): Project
    {
        return Project::where('short_name', $this->shortName)->firstOrFail();
    }

    /**
     * @return Collection<int, Story>
     */
    #[Computed]
    public function stories(): Collection
    {
        return $this->project()->stories()->with(['tasks.assignees', 'tasks.keywords'])->get();
    }

    /**
     * The board columns for this project's tasks.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function columns(): array
    {
        $project = $this->project();

        $tasks = $this->stories()->flatMap(static function ($story) use ($project) {
            $story->setRelation('project', $project);

            return $story->tasks->each(static fn (Task $task) => $task->setRelation('story', $story));
        });

        if ($this->priorityFilter) {
            $tasks = $tasks->filter(fn (Task $task): bool => $task->priority->value === $this->priorityFilter);
        }

        return $this->buildColumns($tasks);
    }

    /**
     * Move a task to a new status column (drag-and-drop drop handler).
     */
    public function moveTask(int $taskId, string $status): void
    {
        $this->applyTaskMove($this->resolveProjectTask($taskId), $status);

        unset($this->stories, $this->columns);
    }

    public function createStory(): void
    {
        $this->authorize('update', $this->project());

        $validated = $this->validate([
            'storyTitle' => ['required', 'string', 'max:255'],
            'storyDescription' => ['nullable', 'string'],
            'storyPriority' => ['required', new Enum(Priority::class)],
            'storyDueDate' => ['nullable', 'date'],
        ]);

        $this->project()->stories()->create([
            'title' => $validated['storyTitle'],
            'description' => $validated['storyDescription'] ?? null,
            'priority' => Priority::from($validated['storyPriority']),
            'due_date' => $validated['storyDueDate'] ?: null,
        ]);

        $this->reset('storyTitle', 'storyDescription', 'storyDueDate', 'showStoryModal');
        unset($this->stories, $this->columns);

        Flux::toast(variant: 'success', text: __('Story created.'));
    }

    public function openTaskModal(?int $storyId = null, ?string $status = null): void
    {
        $this->reset('taskTitle', 'taskDescription', 'taskDueDate');
        $this->taskStoryId = $storyId ?? $this->stories()->first()?->id;
        $this->taskStatus = $status ?? Status::Planned->value;
        $this->taskPriority = $this->priorityForStory($this->taskStoryId);
        $this->showTaskModal = true;
    }

    /**
     * Keep the task priority in sync with the selected story — tasks inherit it.
     */
    public function updatedTaskStoryId(mixed $value): void
    {
        $this->taskPriority = $this->priorityForStory((int) $value);
    }

    /**
     * The priority a new task should default to, inherited from its story.
     */
    protected function priorityForStory(?int $storyId): int
    {
        return $this->stories()->firstWhere('id', $storyId)?->priority->value
            ?? Priority::default()->value;
    }

    public function createTask(): void
    {
        $this->authorize('update', $this->project());

        $validated = $this->validate([
            'taskStoryId' => ['required', 'integer'],
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string'],
            'taskPriority' => ['required', new Enum(Priority::class)],
            'taskDueDate' => ['nullable', 'date'],
            'taskStatus' => ['required', 'string', 'in:'.collect(Status::cases())->map->value->implode(',')],
        ]);

        $story = $this->project()->stories()->whereKey($validated['taskStoryId'])->firstOrFail();

        $task = $story->tasks()->make([
            'title' => $validated['taskTitle'],
            'description' => $validated['taskDescription'] ?? null,
            'due_date' => $validated['taskDueDate'] ?: null,
        ]);
        $task->priority = Priority::from($validated['taskPriority']);
        $task->status = Status::from($validated['taskStatus']);
        $task->save();

        $this->reset('taskTitle', 'taskDescription', 'taskDueDate', 'showTaskModal');
        unset($this->stories, $this->columns);

        Flux::toast(variant: 'success', text: __('Task created.'));
    }

    /**
     * Resolve a task that belongs to this board's project, or 404.
     */
    protected function resolveProjectTask(int $taskId): Task
    {
        $task = Task::with('story')->findOrFail($taskId);

        abort_unless($task->story->project_id === $this->project()->id, 404);

        return $task;
    }
}
