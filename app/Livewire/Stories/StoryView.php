<?php

namespace App\Livewire\Stories;

use App\Concerns\HandlesAttachments;
use App\Enums\Priority;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StoryView extends Component
{
    use HandlesAttachments;

    public string $shortName;

    public int $storyNumber;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    public string $keywords = '';

    public int $priority;

    /** @var array<int, int> */
    public array $assigneeIds = [];

    public function mount(string $short_name, int $story_number): void
    {
        $this->shortName = $short_name;
        $this->storyNumber = $story_number;

        $story = $this->story();
        $this->authorize('view', $story);

        $this->priority = $story->priority->value;
        $this->assigneeIds = $story->assignees->pluck('id')->all();
    }

    #[Computed]
    public function story(): Story
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        return $project->stories()
            ->with(['assignees', 'keywords', 'tasks.assignees', 'project'])
            ->where('story_number', $this->storyNumber)
            ->firstOrFail();
    }

    protected function attachable(): Project|Story|Task
    {
        return $this->story();
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
        $this->keywords = $this->story()->keywordList();
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

        $changes = $story->syncKeywords($this->keywords);
        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $story->recordActivity('keywords_changed', 'keywords');
        }

        $this->editing = false;
        unset($this->story);

        Flux::toast(variant: 'success', text: __('Story updated.'));
    }
}
