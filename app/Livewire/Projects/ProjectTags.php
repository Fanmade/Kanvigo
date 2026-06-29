<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Tag;
use App\Support\IconCatalog;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-project tag catalog management: rename (merging on a name collision),
 * recolor, delete and merge a project's tags. Renaming and recoloring are open
 * to any member holding `manage-tags`; deletion and merging are restricted to
 * `manage-settings` (admin/owner) since they destroy tags. Every change is
 * recorded in the project activity log by the Tag model itself.
 *
 * @property-read Project $project
 * @property-read Collection<int, Tag> $selectedTags
 * @property-read list<string> $palette
 */
class ProjectTags extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    public ?int $editingTagId = null;

    public string $editName = '';

    public string $editColor = 'zinc';

    /**
     * The chosen icon, or null for a colour-only tag. Declared null (not a
     * non-null default) so clearing it survives Livewire's omit-null hydration.
     */
    public ?string $editIcon = null;

    /**
     * The names the edited tag is also found by when searching. Edited as a list
     * of staged chips and persisted on save.
     *
     * @var list<string>
     */
    public array $editSynonyms = [];

    /**
     * The text typed into the "add synonym" field of the edit dialog.
     */
    public string $synonymQuery = '';

    /**
     * The ids of the tags ticked for merging.
     *
     * @var list<int>
     */
    public array $selected = [];

    public bool $merging = false;

    /**
     * The tag the selected tags fold into — the one that survives the merge.
     */
    public ?int $mergeTargetId = null;

    /**
     * Whether merging should keep the folded-in tags' names (and their synonyms)
     * as synonyms of the surviving tag.
     */
    public bool $mergeAsSynonyms = false;

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('manage-tags', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('manage-tags', $project);

        return $project;
    }

    /**
     * The project's tags, alphabetical, each with the number of tasks it is
     * applied to.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return $this->project->tags()
            ->withCount('tasks')
            ->orderBy('name')
            ->get();
    }

    /**
     * The ticked tags, most-used first — the candidates for a merge and the
     * options offered as the surviving tag. Scoped to the project, so a tampered
     * id from another project is silently dropped.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function selectedTags(): Collection
    {
        if ($this->selected === []) {
            return new Collection;
        }

        return $this->project->tags()
            ->withCount('tasks')
            ->whereKey($this->selected)
            ->orderByDesc('tasks_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * The colors a tag may be given: the shared palette plus the neutral default.
     *
     * @return list<string>
     */
    #[Computed]
    public function palette(): array
    {
        return Tag::paletteWithDefault();
    }

    /**
     * The curated Heroicons a tag may carry — the same set as task types.
     *
     * @return list<string>
     */
    #[Computed]
    public function icons(): array
    {
        return IconCatalog::available();
    }

    /**
     * Open the dialog to create a brand-new tag, with empty fields. The same
     * dialog handles edits; a null editingTagId marks create mode.
     */
    public function startCreate(): void
    {
        $this->authorize('manage-tags', $this->project);

        $this->editingTagId = null;
        $this->editName = '';
        $this->editColor = 'zinc';
        $this->editIcon = null;
        $this->editSynonyms = [];
        $this->synonymQuery = '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Open the edit dialog for one of the project's tags.
     */
    public function startEdit(int $tagId): void
    {
        $this->authorize('manage-tags', $this->project);

        $tag = $this->project->tags()->whereKey($tagId)->firstOrFail();

        $this->editingTagId = $tag->id;
        $this->editName = $tag->name;
        $this->editColor = $tag->color;
        $this->editIcon = $tag->icon;

        $synonyms = [];
        foreach ($tag->synonyms()->orderBy('name')->get() as $synonym) {
            $synonyms[] = $synonym->name;
        }
        $this->editSynonyms = $synonyms;
        $this->synonymQuery = '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Clear the chosen icon, so the tag is identified by colour alone.
     */
    public function clearIcon(): void
    {
        $this->editIcon = null;
    }

    /**
     * Stage the typed text as a synonym of the tag being edited, skipping blanks,
     * the tag's own name and any already-staged synonym (case-insensitively).
     */
    public function addSynonym(): void
    {
        $name = trim($this->synonymQuery);
        $this->synonymQuery = '';

        if ($name === '') {
            return;
        }

        $taken = array_map(mb_strtolower(...), [$this->editName, ...$this->editSynonyms]);

        if (in_array(mb_strtolower($name), $taken, true)) {
            return;
        }

        $this->editSynonyms[] = $name;
    }

    /**
     * Remove a staged synonym by its position.
     */
    public function removeSynonym(int $index): void
    {
        $remaining = [];
        foreach ($this->editSynonyms as $position => $synonym) {
            if ($position !== $index) {
                $remaining[] = $synonym;
            }
        }
        $this->editSynonyms = $remaining;
    }

    /**
     * Persist the edited name and color. A name that collides (case-insensitively)
     * with another of the project's tags merges this tag into that one rather
     * than failing the unique constraint.
     */
    public function saveEdit(): void
    {
        $project = $this->project;
        $this->authorize('manage-tags', $project);

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editColor' => Tag::colorRule(allowDefault: true),
            'editIcon' => Tag::iconRule(),
        ]);

        $name = trim($validated['editName']);

        if ($this->editingTagId === null) {
            $this->createTag($project, $name, $validated['editColor']);

            return;
        }

        $tag = $project->tags()->whereKey($this->editingTagId)->firstOrFail();

        $collision = $project->tags()
            ->whereKeyNot($tag->id)
            ->whereNameLower($name)
            ->first();

        if ($collision !== null) {
            $collision->recolor($validated['editColor']);
            $collision->forceFill(['icon' => $this->editIcon])->save();
            $tag->mergeInto($collision);

            Flux::toast(variant: 'success', text: __('Tags merged.'));
        } else {
            $tag->rename($name);
            $tag->recolor($validated['editColor']);
            $tag->forceFill(['icon' => $this->editIcon])->save();
            $tag->syncSynonyms($this->editSynonyms);

            Flux::toast(variant: 'success', text: __('Tag updated.'));
        }

        $this->editing = false;
        $this->editingTagId = null;
        $this->reset('editSynonyms', 'synonymQuery');
        unset($this->tags);
    }

    /**
     * Create a new tag from the dialog. A name that already exists (case-
     * insensitively) in the project is rejected rather than silently merged, so
     * creating never folds into an unrelated tag.
     */
    protected function createTag(Project $project, string $name, string $color): void
    {
        $exists = $project->tags()
            ->whereNameLower($name)
            ->exists();

        if ($exists) {
            $this->addError('editName', __('A tag with that name already exists.'));

            return;
        }

        Tag::findOrCreateForProject($project->id, $name, $color, $this->editIcon)
            ->syncSynonyms($this->editSynonyms);

        $this->editing = false;
        $this->editingTagId = null;
        $this->reset('editSynonyms', 'synonymQuery');
        unset($this->tags);

        Flux::toast(variant: 'success', text: __('Tag created.'));
    }

    /**
     * Delete one of the project's tags, detaching it from every task it was
     * applied to. Restricted to manage-settings (admin/owner).
     */
    public function deleteTag(int $tagId): void
    {
        $project = $this->project;
        $this->authorize('manageSettings', $project);

        $tag = $project->tags()->whereKey($tagId)->firstOrFail();
        $tag->deleteWithActivity();

        unset($this->tags);

        Flux::toast(variant: 'success', text: __('Tag deleted.'));
    }

    /**
     * Open the merge dialog for the ticked tags, defaulting the surviving tag to
     * the most-used of them. A no-op unless at least two tags are selected.
     */
    public function startMerge(): void
    {
        $this->authorize('manageSettings', $this->project);

        $selected = $this->selectedTags;

        if ($selected->count() < 2) {
            return;
        }

        $this->mergeTargetId = $selected->first()->id;
        $this->mergeAsSynonyms = false;
        $this->merging = true;
    }

    /**
     * Fold every other selected tag into the chosen surviving tag — re-pointing
     * their tasks first — then drop them. Restricted to manage-settings
     * (admin/owner). A no-op unless at least two valid tags are selected and the
     * surviving tag is one of them.
     */
    public function mergeTags(): void
    {
        $project = $this->project;
        $this->authorize('manageSettings', $project);

        $selected = $this->selectedTags;
        $target = $selected->firstWhere('id', $this->mergeTargetId);

        if ($target === null || $selected->count() < 2) {
            return;
        }

        $adopt = $this->mergeAsSynonyms;

        $selected->reject(static fn (Tag $tag): bool => $tag->is($target))
            ->each(static fn (Tag $tag) => $tag->mergeInto($target, adoptAsSynonym: $adopt));

        $this->reset('selected', 'merging', 'mergeTargetId', 'mergeAsSynonyms');
        unset($this->tags, $this->selectedTags);

        Flux::toast(variant: 'success', text: __('Tags merged.'));
    }

    public function render(): View
    {
        return view('livewire.projects.project-tags');
    }
}
