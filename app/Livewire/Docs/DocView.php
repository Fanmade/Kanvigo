<?php

namespace App\Livewire\Docs;

use App\Concerns\HandlesAttachments;
use App\Concerns\ShowsReferences;
use App\Livewire\Tasks\TaskView;
use App\Models\Doc;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A single reference doc: its body, the docs nested under it, and the tasks and
 * docs it links to (and that link back to it). Editors write the body with the
 * shared rich-text editor, nest the doc elsewhere in the tree, and publish it to
 * the project — until then it is a draft only editors can see.
 *
 * Like {@see TaskView}, the `doc` computed is read as a
 * property (`$this->doc`) so Livewire memoizes it for the whole request.
 *
 * @property-read Doc $doc
 * @property-read bool $canUpdate
 * @property-read bool $canDelete
 * @property-read bool $canCreate
 * @property-read EloquentCollection<int, Doc> $childDocs
 * @property-read Collection<int, Doc> $ancestors
 */
class DocView extends Component
{
    use HandlesAttachments;
    use ShowsReferences;

    #[Locked]
    public string $shortName;

    #[Locked]
    public int $docNumber;

    public bool $editing = false;

    public string $title = '';

    /**
     * The rich-text body. Named `description` on the task page; here it keeps the
     * model's own name, and the editor is pointed at it explicitly.
     */
    public string $body = '';

    /**
     * The doc this one is nested under while editing, or null for a top-level doc.
     */
    public ?int $parentId = null;

    /**
     * Create-a-nested-doc dialog state.
     */
    public bool $creatingChild = false;

    public string $childTitle = '';

    public function mount(string $short_name, int $doc_number): void
    {
        $this->shortName = $short_name;
        $this->docNumber = $doc_number;

        $this->authorize('view', $this->doc);
    }

    #[Computed]
    public function doc(): Doc
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $doc = Doc::query()
            ->with(['project', 'parent', 'children', ...Doc::referenceItemsEagerLoad()])
            ->where('project_id', $project->id)
            ->where('doc_number', $this->docNumber)
            ->firstOrFail();

        $this->authorize('view', $doc);

        return $doc;
    }

    protected function attachable(): Doc
    {
        return $this->doc;
    }

    protected function referenceable(): Doc
    {
        return $this->doc;
    }

    /**
     * The endpoint the editor fetches @mention / #reference suggestions from.
     */
    #[Computed]
    public function mentionablesUrl(): string
    {
        return route('project.mentionables', $this->doc->project);
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->doc);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Gate::allows('delete', $this->doc);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Gate::allows('create-doc', $this->doc->project);
    }

    /**
     * The docs nested directly under this one that the viewer may see: drafts
     * only for editors, mirroring the doc policy.
     *
     * @return Collection<int, Doc>
     */
    #[Computed]
    public function childDocs(): Collection
    {
        return $this->canUpdate
            ? $this->doc->children
            : $this->doc->children->where('is_public', true)->values();
    }

    /**
     * The path from the tree's root down to (but excluding) this doc, for the
     * breadcrumb. Ancestors the viewer cannot see are left out.
     *
     * @return Collection<int, Doc>
     */
    #[Computed]
    public function ancestors(): Collection
    {
        $ancestors = new Collection;

        for ($doc = $this->doc->parent; $doc !== null; $doc = $doc->parent) {
            if (! Gate::allows('view', $doc)) {
                break;
            }

            $ancestors->prepend($doc);
        }

        return $ancestors;
    }

    /**
     * The docs this one may be nested under, as `[id => "REF · title"]`: every
     * other doc in the project except this one and the docs nested under it,
     * which would close a cycle.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentOptions(): array
    {
        $docs = $this->doc->project->docs()->orderBy('position')->orderBy('doc_number')->get();
        $excluded = $this->descendantIds($docs, $this->doc->getKey());

        return $docs
            ->reject(static fn (Doc $doc): bool => isset($excluded[$doc->getKey()]))
            ->mapWithKeys(static fn (Doc $doc): array => [(int) $doc->getKey() => $doc->reference.' · '.$doc->title])
            ->all();
    }

    public function edit(): void
    {
        $doc = $this->doc;
        $this->authorize('update', $doc);

        $this->title = $doc->title;
        $this->body = (string) $doc->body;
        $this->parentId = $doc->parent_id;
        $this->editing = true;
    }

    public function save(): void
    {
        $doc = $this->doc;
        $this->authorize('update', $doc);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'parentId' => [
                'nullable',
                'integer',
                Rule::exists('docs', 'id')->where('project_id', $doc->project_id)->whereNull('deleted_at'),
            ],
        ]);

        // The model rejects a parent that closes a cycle or nests too deep; show
        // that as an error on the field instead of a 500.
        try {
            $doc->update([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'parent_id' => $validated['parentId'],
            ]);
        } catch (InvalidArgumentException) {
            $this->addError('parentId', __('This doc cannot be nested there — a doc cannot sit under itself or its own nested docs, and the tree is limited to :depth levels.', ['depth' => Doc::MAX_NESTING_DEPTH]));

            return;
        }

        $this->editing = false;
        unset($this->doc, $this->parentOptions);

        Flux::toast(text: __('Doc updated.'), variant: 'success');
    }

    /**
     * Open the dialog for a doc nested under this one.
     */
    public function startCreatingChild(): void
    {
        $this->authorize('create-doc', $this->doc->project);

        $this->reset('childTitle');
        $this->resetValidation();
        $this->creatingChild = true;
    }

    /**
     * Create a draft doc nested under this one and open it.
     */
    public function createChild(): void
    {
        $doc = $this->doc;
        $this->authorize('create-doc', $doc->project);

        $validated = $this->validate(['childTitle' => ['required', 'string', 'max:255']]);

        // The model rejects a child that would nest past the depth limit.
        try {
            $child = $doc->project->docs()->create([
                'title' => $validated['childTitle'],
                'parent_id' => $doc->getKey(),
            ]);
        } catch (InvalidArgumentException) {
            $this->addError('childTitle', __('Docs cannot be nested deeper than :depth levels.', ['depth' => Doc::MAX_NESTING_DEPTH]));

            return;
        }

        Flux::toast(text: __('Doc created.'), variant: 'success');

        $this->redirectRoute('doc.show', [
            'short_name' => $doc->project->short_name,
            'doc_number' => $child->doc_number,
        ], navigate: true);
    }

    /**
     * Publish the draft to the project, or take a published doc back to a draft.
     */
    public function togglePublished(): void
    {
        $doc = $this->doc;
        $this->authorize('update', $doc);

        $doc->update(['is_public' => ! $doc->is_public]);

        unset($this->doc);

        Flux::toast(
            text: $doc->is_public ? __('Doc published to the project.') : __('Doc moved back to draft.'),
            variant: 'success',
        );
    }

    /**
     * Delete the doc and return to the project's doc index. The delete is soft,
     * and the docs nested under it are kept — they surface at the top level of
     * the tree while their parent is gone, so nothing is stranded behind it.
     */
    public function delete(): void
    {
        $doc = $this->doc;
        $this->authorize('delete', $doc);

        $shortName = $doc->project->short_name;
        $doc->delete();

        Flux::toast(text: __('Doc deleted.'), variant: 'success');

        $this->redirectRoute('project.docs', ['short_name' => $shortName], navigate: true);
    }

    /**
     * The ids of the given doc and everything nested under it, as a lookup set.
     *
     * @param  EloquentCollection<int, Doc>  $docs
     * @return array<int, bool>
     */
    private function descendantIds(EloquentCollection $docs, int $rootId): array
    {
        $excluded = [$rootId => true];

        // The tree is shallow (bounded by Doc::MAX_NESTING_DEPTH) and already in
        // memory, so repeated passes settle after a handful of rounds.
        do {
            $added = false;

            foreach ($docs as $doc) {
                if ($doc->parent_id !== null && ! isset($excluded[$doc->getKey()]) && isset($excluded[$doc->parent_id])) {
                    $excluded[$doc->getKey()] = true;
                    $added = true;
                }
            }
        } while ($added);

        return $excluded;
    }

    /**
     * Put the doc's reference and title in the browser tab, e.g. "KAN-D3 · Style
     * guide", so several open doc tabs stay distinguishable.
     */
    public function render(): View
    {
        $doc = $this->doc;

        return view('livewire.docs.doc-view')
            ->title($doc->reference.' · '.$doc->title);
    }
}
