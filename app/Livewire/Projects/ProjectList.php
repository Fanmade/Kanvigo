<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Projects')]
class ProjectList extends Component
{
    /**
     * Whether the create-project modal is open. URL-bound (aliased to `create`)
     * so the command palette can deep-link straight to the open form.
     */
    #[Url(as: 'create')]
    public bool $showCreate = false;

    public string $title = '';

    public string $short_name = '';

    public string $description = '';

    /**
     * The projects the current user has access to.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Auth::user()->projects()->orderBy('title')->get();
    }

    /**
     * Suggest a short name from the title while the user hasn't set one.
     */
    public function updatedTitle(): void
    {
        if (trim($this->short_name) === '') {
            $this->short_name = Project::shortNameFromTitle($this->title);
        }
    }

    public function createProject(): void
    {
        $this->authorize('create-projects');

        $this->short_name = strtoupper($this->short_name);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_name' => [
                'required', 'string', 'min:2', 'max:4', 'alpha', 'uppercase',
                Rule::notIn(['WWW', 'API', 'APP', 'FTP']),
                'unique:projects,short_name',
            ],
            'description' => ['nullable', 'string'],
        ]);

        $project = Project::create($validated);
        $project->members()->attach(Auth::id(), ['role' => ProjectRole::Owner->value]);

        Flux::toast(text: __('Project created.'), variant: 'success');

        $this->redirectRoute('project.show', ['short_name' => $project->short_name], navigate: true);
    }
}
