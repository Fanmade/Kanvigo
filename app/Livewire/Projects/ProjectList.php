<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Projects')]
class ProjectList extends Component
{
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

    public function createProject(): void
    {
        Gate::authorize('create-projects');

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
        $project->members()->attach(Auth::id());

        Flux::toast(variant: 'success', text: __('Project created.'));

        $this->redirectRoute('project.show', ['short_name' => $project->short_name], navigate: true);
    }
}
