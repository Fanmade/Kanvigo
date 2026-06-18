<?php

namespace App\Livewire\Projects;

use App\Concerns\HandlesAttachments;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
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

    public bool $showStoryModal = false;

    public string $storyTitle = '';

    public string $storyDescription = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project());
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)
            ->with(['stories.keywords', 'stories.tasks.assignees'])
            ->firstOrFail();

        $this->authorize('view', $project);

        return $project;
    }

    protected function attachable(): Project|Story|Task
    {
        return $this->project();
    }

    /**
     * Stories that have at least one unfinished task.
     *
     * @return Collection<int, Story>
     */
    #[Computed]
    public function openStories(): Collection
    {
        return $this->project()->stories
            ->filter(static fn (Story $story) => $story->tasks->contains(static fn ($task) => $task->status !== Status::Done))
            ->values();
    }

    /**
     * Stories whose tasks are all done.
     *
     * @return Collection<int, Story>
     */
    #[Computed]
    public function completedStories(): Collection
    {
        return $this->project()->stories
            ->filter(static fn (Story $story) => $story->tasks->isNotEmpty()
                && $story->tasks->every(static fn ($task) => $task->status === Status::Done))
            ->values();
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

    public function createStory(): void
    {
        $this->authorize('update', $this->project());

        $validated = $this->validate([
            'storyTitle' => ['required', 'string', 'max:255'],
            'storyDescription' => ['nullable', 'string'],
        ]);

        $this->project()->stories()->create([
            'title' => $validated['storyTitle'],
            'description' => $validated['storyDescription'] ?? null,
        ]);

        $this->reset('storyTitle', 'storyDescription', 'showStoryModal');
        unset($this->project);

        Flux::toast(variant: 'success', text: __('Story created.'));
    }
}
