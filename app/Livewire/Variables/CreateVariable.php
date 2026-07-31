<?php

namespace App\Livewire\Variables;

use App\Models\Project;
use App\Models\Variable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The create-on-demand dialog behind the editor's `[` picker: define a variable
 * without leaving what you are writing.
 *
 * One instance per page, not per editor — the picker remembers which editor asked
 * and inserts `[name]` there when this component announces the variable exists
 * (see resources/js/mentions.js).
 *
 * @property-read Project $project
 */
class CreateVariable extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $shortName;

    public bool $open = false;

    public string $name = '';

    public string $value = '';

    public function mount(string $shortName): void
    {
        $this->shortName = $shortName;

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
     * Open the dialog with the name the author was typing when they asked for it.
     */
    #[On('create-variable')]
    public function open(string $name = ''): void
    {
        $this->authorize('manage-variables', $this->project);

        $this->name = Variable::normalizeName($name);
        $this->value = '';
        $this->resetValidation();
        $this->open = true;
    }

    /**
     * Create the variable, then tell the editor to insert the usage. The value may
     * be left empty — an undecided variable is the whole point.
     */
    public function save(): void
    {
        $project = $this->project;
        $this->authorize('manage-variables', $project);

        $this->name = Variable::normalizeName($this->name);

        $this->validate([
            'name' => Variable::nameRules($project),
            'value' => Variable::valueRules(),
        ], [
            'name.regex' => __('A name starts with a letter and uses only lowercase letters, digits, underscores and hyphens.'),
            'name.unique' => __('A variable with that name already exists.'),
        ]);

        $project->variables()->create(['name' => $this->name, 'value' => $this->value]);

        $this->open = false;

        $this->dispatch('variable-created', name: $this->name);

        Flux::toast(text: __('Variable created.'), variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.variables.create-variable');
    }
}
