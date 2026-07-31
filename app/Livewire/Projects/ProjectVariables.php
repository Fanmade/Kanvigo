<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Variable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-project variable management: create a variable, set what it stands for,
 * change its description and delete it. One `manage-variables` permission covers
 * all of it — a variable is a single fact, so there is nothing to split.
 *
 * Deleting never touches content: the usages stay written as `[name]` and simply
 * start rendering as unset, so recreating the variable brings them back. The
 * confirmation says so.
 *
 * Renaming from here is deliberately absent: it rewrites every usage and is its
 * own flow (KAN-461).
 *
 * @property-read Project $project
 * @property-read Collection<int, Variable> $variables
 */
class ProjectVariables extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    /** The variable being edited, or null while creating a new one. */
    public ?int $editingVariableId = null;

    public string $editName = '';

    public string $editValue = '';

    public string $editDescription = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('manage-variables', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('manage-variables', $project);

        return $project;
    }

    /**
     * The project's variables, alphabetical.
     *
     * @return Collection<int, Variable>
     */
    #[Computed]
    public function variables(): Collection
    {
        return $this->project->variables()->get();
    }

    /**
     * Open the dialog to create a brand-new variable. The same dialog handles
     * edits; a null editingVariableId marks create mode.
     */
    public function startCreate(): void
    {
        $this->authorize('manage-variables', $this->project);

        $this->editingVariableId = null;
        $this->editName = '';
        $this->editValue = '';
        $this->editDescription = '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Open the edit dialog for one of the project's variables.
     */
    public function startEdit(int $variableId): void
    {
        $this->authorize('manage-variables', $this->project);

        $variable = $this->project->variables()->whereKey($variableId)->firstOrFail();

        $this->editingVariableId = $variable->id;
        $this->editName = $variable->name;
        $this->editValue = $variable->value ?? '';
        $this->editDescription = $variable->description ?? '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Create the variable, or save the edited one. The name is normalized before
     * validation so the uniqueness check compares what would actually be stored.
     */
    public function save(): void
    {
        $project = $this->project;
        $this->authorize('manage-variables', $project);

        $variable = $this->editingVariableId === null
            ? null
            : $project->variables()->whereKey($this->editingVariableId)->firstOrFail();

        $this->editName = Variable::normalizeName($this->editName);

        $this->validate([
            'editName' => Variable::nameRules($project, $variable),
            'editValue' => Variable::valueRules(),
            'editDescription' => Variable::descriptionRules(),
        ], [
            'editName.regex' => __('A name starts with a letter and uses only lowercase letters, digits, underscores and hyphens.'),
            'editName.unique' => __('A variable with that name already exists.'),
        ], [
            'editName' => __('name'),
            'editValue' => __('value'),
            'editDescription' => __('description'),
        ]);

        $attributes = [
            'name' => $this->editName,
            'value' => $this->editValue,
            'description' => $this->editDescription,
        ];

        if ($variable === null) {
            $project->variables()->create($attributes);
        } else {
            $variable->update($attributes);
        }

        $this->editing = false;
        $this->editingVariableId = null;
        unset($this->variables);

        Flux::toast(text: $variable === null ? __('Variable created.') : __('Variable updated.'), variant: 'success');
    }

    /**
     * Delete one of the project's variables. Content is left alone: its usages
     * become unknown names and render as unset until it is recreated.
     */
    public function deleteVariable(int $variableId): void
    {
        $project = $this->project;
        $this->authorize('manage-variables', $project);

        $project->variables()->whereKey($variableId)->firstOrFail()->delete();

        unset($this->variables);

        Flux::toast(text: __('Variable deleted.'), variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.projects.project-variables')
            ->title($this->project->short_name.' · '.__('Variables'));
    }
}
