<?php

namespace App\Livewire\Projects;

use App\Jobs\RewriteVariableUsages;
use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableUsage;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
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
 * Renaming is confirmed separately, because it is the one operation that
 * rewrites content: the document goes on saying the same thing, so its usages
 * have to follow the new name ({@see RewriteVariableUsages}).
 *
 * @property-read Project $project
 * @property-read Collection<int, Variable> $variables
 * @property-read array<string, int> $usageCounts
 * @property-read array<string, int> $unknownNames
 * @property-read int $renameUsageCount
 * @property-read Collection<int, Activity> $history
 * @property-read SupportCollection<int, Project|Task|Doc> $usages
 */
class ProjectVariables extends Component
{
    use AuthorizesRequests;

    /**
     * How many usages and history entries the details dialog shows. A bound, not
     * a claim of completeness — the dialog says as much.
     */
    public const int USAGES_SHOWN = 25;

    public const int HISTORY_SHOWN = 25;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    /** Whether the details dialog is open, and for which name. */
    public bool $inspecting = false;

    /**
     * The name being inspected. A name, not an id, so an unknown name — one used
     * in the text with no variable behind it — can be inspected too.
     */
    public ?string $inspectedName = null;

    /** Whether the rename confirmation is open, awaiting a yes. */
    public bool $confirmingRename = false;

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
     * How often each name is used across the project's content, from the usage
     * index. Derived state that is allowed to lag, so it is read for display
     * only — never to decide what a page renders.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function usageCounts(): array
    {
        return VariableUsage::query()
            ->where('project_id', $this->project->getKey())
            ->groupBy('name')
            ->selectRaw('name, count(*) as total')
            ->pluck('total', 'name')
            ->all();
    }

    /**
     * Names used in this project's content that no variable defines — written
     * before the variable was created, or left behind when one was deleted.
     * Listing them is how they get resolved rather than lost.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function unknownNames(): array
    {
        $defined = $this->variables->pluck('name')->all();

        return array_diff_key($this->usageCounts, array_flip($defined));
    }

    /**
     * Open the details of one name: where it is used, and — for a name that is
     * actually a variable — what it has stood for over time.
     */
    public function inspect(string $name): void
    {
        $this->authorize('manage-variables', $this->project);

        $this->inspectedName = Variable::normalizeName($name);
        $this->inspecting = true;

        unset($this->usages, $this->history);
    }

    /**
     * The pages whose text uses the inspected name, newest first.
     *
     * Read from the usage index, which is maintained asynchronously and may lag
     * a very recent edit — fine for a panel, which is why the dialog says so and
     * why nothing on a render path reads this table. Pages the viewer may not see
     * are left out, so the list can never disclose a draft doc.
     *
     * @return SupportCollection<int, Project|Task|Doc>
     */
    #[Computed]
    public function usages(): SupportCollection
    {
        if ($this->inspectedName === null) {
            return new SupportCollection;
        }

        $user = Auth::user();

        return VariableUsage::query()
            ->where('project_id', $this->project->getKey())
            ->where('name', $this->inspectedName)
            ->with('usable')
            ->latest('id')
            ->limit(self::USAGES_SHOWN)
            ->get()
            ->map(static fn (VariableUsage $usage): Project|Task|Doc|null => $usage->page())
            ->filter(static fn (?Model $page): bool => $page !== null && (bool) $user?->can('view', $page))
            ->unique(static fn (Model $page): string => $page::class.':'.$page->getKey())
            ->values();
    }

    /**
     * The audit entries recorded on the inspected variable, newest first — the
     * same events the project feed carries, narrowed to this one. Empty for an
     * unknown name: nothing has ever happened to a variable that does not exist.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function history(): Collection
    {
        $variable = $this->inspectedName === null
            ? null
            : $this->project->variables()->where('name', $this->inspectedName)->first();

        if ($variable === null || ! Auth::user()?->can('view-activity-log', $this->project)) {
            return new Collection;
        }

        return $variable->activities()->with('user')->limit(self::HISTORY_SHOWN)->get();
    }

    /**
     * How a usage is labelled in the list: its reference for a task or doc, the
     * short name for the project itself.
     */
    public function usageLabel(Project|Task|Doc $page): string
    {
        return $page instanceof Project ? $page->short_name : $page->reference;
    }

    /**
     * Where a usage links to.
     */
    public function usageUrl(Project|Task|Doc $page): string
    {
        return match (true) {
            $page instanceof Task => route('task.show', ['short_name' => $page->project->short_name, 'task_number' => $page->task_number]),
            $page instanceof Doc => route('doc.show', ['short_name' => $page->project->short_name, 'doc_number' => $page->doc_number]),
            $page instanceof Project => route('project.show', ['short_name' => $page->short_name]),
        };
    }

    /**
     * Open the dialog to create a brand-new variable, optionally pre-filled with
     * a name already used in the content. The same dialog handles edits; a null
     * editingVariableId marks create mode.
     */
    public function startCreate(string $name = ''): void
    {
        $this->authorize('manage-variables', $this->project);

        $this->editingVariableId = null;
        $this->confirmingRename = false;
        $this->editName = $name;
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
        $this->confirmingRename = false;
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

        // A rename is a refactor, not an edit: it rewrites every usage in the
        // project's content, so it is confirmed separately.
        if ($variable !== null && $this->editName !== $variable->name) {
            $this->confirmingRename = true;

            return;
        }

        $this->persist($project, $variable);
    }

    /**
     * Apply a confirmed rename: save the variable under its new name, then
     * rewrite its usages so no document is left pointing at a name that no
     * longer exists.
     */
    public function rename(): void
    {
        $project = $this->project;
        $this->authorize('manage-variables', $project);

        $variable = $project->variables()->whereKey($this->editingVariableId)->firstOrFail();

        $this->editName = Variable::normalizeName($this->editName);

        // Re-validate: the name has been through another request since save().
        $this->validate(
            ['editName' => Variable::nameRules($project, $variable)],
            ['editName.regex' => __('A name starts with a letter and uses only lowercase letters, digits, underscores and hyphens.'),
                'editName.unique' => __('A variable with that name already exists.')],
            ['editName' => __('name')],
        );

        $from = $variable->name;

        $this->confirmingRename = false;
        $this->persist($project, $variable, __('Variable renamed. Its usages are being updated.'));

        $actor = Auth::user();

        RewriteVariableUsages::dispatch(
            $project->getKey(),
            $from,
            $this->editName,
            $actor instanceof User ? $actor->id : null,
        );
    }

    /**
     * How many usages a rename would rewrite, from the usage index. A count that
     * lags is a count that is a little low — the rename itself re-reads each
     * item's current content.
     */
    #[Computed]
    public function renameUsageCount(): int
    {
        $variable = $this->editingVariableId === null
            ? null
            : $this->variables->firstWhere('id', $this->editingVariableId);

        return $variable === null ? 0 : ($this->usageCounts[$variable->name] ?? 0);
    }

    /**
     * Write the dialog's fields to a new or existing variable and close it.
     */
    protected function persist(Project $project, ?Variable $variable, ?string $message = null): void
    {
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
        unset($this->variables, $this->unknownNames, $this->renameUsageCount);

        $message ??= $variable === null ? __('Variable created.') : __('Variable updated.');

        Flux::toast(text: $message, variant: 'success');
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

        // The usages stay; they simply become unknown names from now on.
        unset($this->variables, $this->unknownNames);

        Flux::toast(text: __('Variable deleted.'), variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.projects.project-variables')
            ->title($this->project->short_name.' · '.__('Variables'));
    }
}
