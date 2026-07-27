<?php

namespace App\Livewire\Docs;

use App\Models\Doc;
use App\Models\Project;
use App\Policies\DocPolicy;
use App\Support\GlobalSearch;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A project's doc index: every reference doc the viewer may see, as the nested
 * tree the docs are organized into, plus a search that flattens the tree to the
 * matching docs. Creating a doc opens the new (draft) doc straight away, so the
 * body is written on the doc's own page rather than in a dialog.
 *
 * @property-read Project $project
 * @property-read bool $canEdit
 * @property-read bool $canCreate
 * @property-read EloquentCollection<int, Doc> $docs
 * @property-read Collection<array-key, EloquentCollection<int, Doc>> $docsByParent
 * @property-read bool $isSearching
 */
class DocList extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $shortName;

    /**
     * Free-text query matched against doc titles and bodies.
     */
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Whether the create-doc dialog is open. URL-bound (aliased to `create`) so
     * the command palette can deep-link straight to the open form.
     */
    #[Url(as: 'create')]
    public bool $creating = false;

    public string $newTitle = '';

    /**
     * The parent the new doc is nested under, or null for a top-level doc.
     */
    public ?int $newParentId = null;

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::where('short_name', $this->shortName)->firstOrFail();
    }

    /**
     * Whether the viewer may edit this project's docs — which is also what makes
     * drafts visible to them (see {@see DocPolicy::view()}).
     */
    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit-doc', $this->project);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Gate::allows('create-doc', $this->project);
    }

    /**
     * The docs the viewer may see, narrowed by the search. Drafts are included
     * only for editors, mirroring the doc policy as one query instead of a
     * per-row authorization check.
     *
     * @return EloquentCollection<int, Doc>
     */
    #[Computed]
    public function docs(): EloquentCollection
    {
        $docs = $this->project->docs()
            ->orderBy('position')
            ->orderBy('doc_number');

        if (! $this->canEdit) {
            $docs->where('is_public', true);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $operator = GlobalSearch::likeOperatorFor((new Doc)->getConnection()->getDriverName());

            $docs->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhere('body', $operator, $like));
        }

        return $docs->get();
    }

    /**
     * The docs grouped under the id of their parent, keyed "root" for the ones
     * shown at the top level: docs without a parent, plus any whose parent the
     * viewer cannot see (a published doc under a draft), so no doc is stranded
     * out of the tree.
     *
     * @return Collection<array-key, EloquentCollection<int, Doc>>
     */
    #[Computed]
    public function docsByParent(): Collection
    {
        $visible = array_flip($this->docs->modelKeys());

        return $this->docs->groupBy(static fn (Doc $doc): int|string => $doc->parent_id !== null && isset($visible[$doc->parent_id])
            ? $doc->parent_id
            : 'root');
    }

    /**
     * Whether the search is narrowing the list. The tree only makes sense over
     * the full set — a filtered tree hides matches under non-matching parents —
     * so a search renders its matches as a flat list instead.
     */
    #[Computed]
    public function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    /**
     * Open the create dialog, optionally nesting the new doc under a parent.
     */
    public function startCreating(?int $parentId = null): void
    {
        $this->authorize('create-doc', $this->project);

        $this->reset('newTitle');
        $this->resetValidation();
        $this->newParentId = $parentId;
        $this->creating = true;
    }

    /**
     * Create the doc as a draft and open it, where its body is written.
     */
    public function create(): void
    {
        $project = $this->project;
        $this->authorize('create-doc', $project);

        $validated = $this->validate([
            'newTitle' => ['required', 'string', 'max:255'],
            'newParentId' => [
                'nullable',
                'integer',
                Rule::exists('docs', 'id')->where('project_id', $project->id)->whereNull('deleted_at'),
            ],
        ]);

        $doc = $project->docs()->create([
            'title' => $validated['newTitle'],
            'parent_id' => $validated['newParentId'],
        ]);

        Flux::toast(text: __('Doc created.'), variant: 'success');

        $this->redirectRoute('doc.show', [
            'short_name' => $project->short_name,
            'doc_number' => $doc->doc_number,
        ], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.docs.doc-list')
            ->title($this->project->short_name.' · '.__('Docs'));
    }
}
