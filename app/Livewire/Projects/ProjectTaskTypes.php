<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Tag;
use App\Models\TaskType;
use App\Support\IconCatalog;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-project task-type management: add, rename, recolor, re-icon, reorder and
 * delete the types tasks can be classified with. Configuring a project's type
 * catalog is a settings-level action, so the whole screen is gated on
 * `manage-settings` (admins and the owner). Deleting a type leaves its tasks
 * untyped (the `task_type_id` foreign key is null-on-delete).
 *
 * @property-read Project $project
 * @property-read list<string> $palette
 */
class ProjectTaskTypes extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    /** The type being edited, or null while creating a new one. */
    public ?int $editingTypeId = null;

    public string $editName = '';

    public string $editColor = 'sky';

    /**
     * The chosen icon, or null for a colour-only type. Declared null (not 'tag')
     * so that when the icon is cleared, Livewire's omit-null hydration falls back
     * to null rather than re-applying a non-null default.
     */
    public ?string $editIcon = null;

    public string $editBranchPrefix = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('manageSettings', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('manageSettings', $project);

        return $project;
    }

    /**
     * The project's task types in display order, each with the number of tasks it
     * is applied to.
     *
     * @return Collection<int, TaskType>
     */
    #[Computed]
    public function taskTypes(): Collection
    {
        return $this->project->taskTypes()
            ->withCount('tasks')
            ->get();
    }

    /**
     * The colors a type may be given: the shared palette plus the neutral default.
     *
     * @return list<string>
     */
    #[Computed]
    public function palette(): array
    {
        return Tag::paletteWithDefault();
    }

    /**
     * The icons a type may be given.
     *
     * @return list<string>
     */
    #[Computed]
    public function icons(): array
    {
        return IconCatalog::available();
    }

    /**
     * Open the dialog to create a new type.
     */
    public function startCreate(): void
    {
        $this->authorize('manageSettings', $this->project);

        $this->editingTypeId = null;
        $this->editName = '';
        $this->editColor = 'sky';
        $this->editIcon = 'tag';
        $this->editBranchPrefix = '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Open the dialog to edit one of the project's types.
     */
    public function startEdit(int $typeId): void
    {
        $this->authorize('manageSettings', $this->project);

        $type = $this->project->taskTypes()->whereKey($typeId)->firstOrFail();

        $this->editingTypeId = $type->id;
        $this->editName = $type->name;
        $this->editColor = $type->color;
        $this->editIcon = $type->icon;
        $this->editBranchPrefix = (string) $type->branch_prefix;
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Clear the chosen icon, so the type is identified by colour alone.
     */
    public function clearIcon(): void
    {
        $this->editIcon = null;
    }

    /**
     * Create the new type, or persist edits to the existing one. Names must be
     * unique within the project, case-insensitively.
     */
    public function save(): void
    {
        $project = $this->project;
        $this->authorize('manageSettings', $project);

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editColor' => Tag::colorRule(allowDefault: true),
            'editIcon' => Tag::iconRule(),
            'editBranchPrefix' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/-]*$/'],
        ]);

        $name = trim($validated['editName']);

        $collision = $project->taskTypes()
            ->when($this->editingTypeId !== null, fn ($query) => $query->whereKeyNot($this->editingTypeId))
            ->whereNameLower($name)
            ->exists();

        if ($collision) {
            $this->addError('editName', __('A type with that name already exists.'));

            return;
        }

        $attributes = [
            'name' => $name,
            'color' => $validated['editColor'],
            'icon' => $this->editIcon,
            'branch_prefix' => trim((string) $validated['editBranchPrefix']) ?: null,
        ];

        if ($this->editingTypeId === null) {
            $project->taskTypes()->create([
                ...$attributes,
                'position' => (int) $project->taskTypes()->max('position') + 1,
            ]);

            Flux::toast(text: __('Type created.'), variant: 'success');
        } else {
            $project->taskTypes()->whereKey($this->editingTypeId)->firstOrFail()->update($attributes);

            Flux::toast(text: __('Type updated.'), variant: 'success');
        }

        $this->editing = false;
        $this->editingTypeId = null;
        unset($this->taskTypes);
    }

    /**
     * Delete one of the project's types. Its tasks keep their data but become
     * untyped (the foreign key is null-on-delete).
     */
    public function deleteType(int $typeId): void
    {
        $project = $this->project;
        $this->authorize('manageSettings', $project);

        $project->taskTypes()->whereKey($typeId)->firstOrFail()->delete();

        unset($this->taskTypes);

        Flux::toast(text: __('Type deleted.'), variant: 'success');
    }

    /**
     * Move a type one step earlier in the display order, swapping positions with
     * its predecessor.
     */
    public function moveUp(int $typeId): void
    {
        $this->swapWithNeighbour($typeId, -1);
    }

    /**
     * Move a type one step later in the display order, swapping positions with
     * its successor.
     */
    public function moveDown(int $typeId): void
    {
        $this->swapWithNeighbour($typeId, 1);
    }

    /**
     * Swap a type's position with the neighbour $offset steps away in the current
     * order, persisting both. A no-op at the ends of the list.
     */
    protected function swapWithNeighbour(int $typeId, int $offset): void
    {
        $this->authorize('manageSettings', $this->project);

        $types = $this->project->taskTypes()->get()->values();
        $index = $types->search(static fn (TaskType $type): bool => $type->id === $typeId);

        if ($index === false) {
            return;
        }

        $neighbour = $types->get($index + $offset);

        if ($neighbour === null) {
            return;
        }

        $current = $types->get($index);

        [$current->position, $neighbour->position] = [$neighbour->position, $current->position];
        $current->save();
        $neighbour->save();

        unset($this->taskTypes);
    }

    public function render(): View
    {
        return view('livewire.projects.project-task-types');
    }
}
