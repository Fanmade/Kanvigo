<?php

namespace App\Concerns;

use App\Contracts\Dependable;
use App\Enums\RelationshipType;
use App\Models\Dependency;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Flux\Flux;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Adds dependency management to a Task view component: listing an item's
 * blockers and the items it blocks, and adding or removing links by reference
 * (e.g. "ABC-42").
 */
trait ManagesDependencies
{
    public string $dependencyReference = '';

    public string $dependencyDirection = 'blocked_by';

    /**
     * The task whose dependencies are being managed.
     */
    abstract protected function dependable(): Task;

    /**
     * The viewed item's relationships, grouped by keyword in display order. Each
     * group carries a heading and the links to render (the related task plus the
     * link id used to remove it). Links where the item is the dependent (object)
     * end and where it is the blocker (subject) end are both gathered, so a
     * symmetric "relates" link appears once regardless of which end the item is.
     *
     * @return BaseCollection<int, array{keyword: string, heading: string, links: BaseCollection<int, array{link: Dependency, related: Task}>}>
     */
    #[Computed]
    public function relationshipGroups(): BaseCollection
    {
        $item = $this->dependable();

        /** @var list<array{link: Dependency, related: Task, keyword: string}> $rows */
        $rows = [];

        foreach ($item->dependencyLinks()->with('blocker')->get() as $link) {
            $related = $link->blocker;

            if ($related instanceof Task) {
                $rows[] = ['link' => $link, 'related' => $related, 'keyword' => $link->type->keyword(asSubject: false)];
            }
        }

        foreach ($item->dependentLinks()->with('dependent')->get() as $link) {
            $related = $link->dependent;

            if ($related instanceof Task) {
                $rows[] = ['link' => $link, 'related' => $related, 'keyword' => $link->type->keyword(asSubject: true)];
            }
        }

        $byKeyword = collect($rows)->groupBy('keyword');

        $groups = [];

        foreach (RelationshipType::keywords() as $keyword) {
            $group = $byKeyword->get($keyword);
            $resolved = RelationshipType::fromKeyword($keyword);

            if ($group === null || $resolved === null) {
                continue;
            }

            [$type, $asSubject] = $resolved;

            $groups[] = [
                'keyword' => $keyword,
                'heading' => $type->groupHeading($asSubject),
                'links' => $group
                    ->map(static fn (array $row): array => ['link' => $row['link'], 'related' => $row['related']])
                    ->values(),
            ];
        }

        return collect($groups);
    }

    /**
     * The relationship options offered in the add form: keyword => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function relationshipOptions(): array
    {
        return RelationshipType::options();
    }

    /**
     * Whether the viewed item has an unfinished blocker. Only blocking links
     * count — informational links (relates, duplicates, …) never block.
     */
    #[Computed]
    public function isBlocked(): bool
    {
        return $this->dependable()
            ->dependencyLinks()
            ->where('type', RelationshipType::Blocks->value)
            ->with('blocker')
            ->get()
            ->contains(static fn (Dependency $link): bool => $link->blocker instanceof Dependable && ! $link->blocker->isComplete());
    }

    /**
     * Same-project tasks matching the typed reference/title, offered as
     * suggestions for the dependency picker. Empty until the user types, and
     * capped so the query stays bounded on large projects. Matches a title
     * substring or an exact task number, and never offers the viewed item.
     *
     * @return BaseCollection<int, array{reference: non-falsy-string, label: non-falsy-string}>
     */
    #[Computed]
    public function dependencyCandidates(): BaseCollection
    {
        $term = trim($this->dependencyReference);

        if ($term === '') {
            return new BaseCollection;
        }

        $item = $this->dependable();
        $project = $item->project;
        $digits = (string) preg_replace('/\D+/', '', $term);

        return Task::query()
            ->where('project_id', $project->id)
            ->whereKeyNot($item->getKey())
            ->where(static function ($query) use ($term, $digits): void {
                $query->whereLike('title', '%'.$term.'%');

                if ($digits !== '') {
                    $query->orWhere('task_number', (int) $digits);
                }
            })
            ->orderBy('task_number')
            ->limit(10)
            ->get()
            ->each(static fn (Task $task) => $task->setRelation('project', $project))
            ->map(static fn (Task $task): array => [
                'reference' => $task->reference,
                'label' => $task->reference.' · '.$task->title,
            ])
            ->values();
    }

    /**
     * Whether the current user may add or remove this item's dependencies.
     */
    #[Computed]
    public function canManageDependencies(): bool
    {
        return Gate::allows('update', $this->dependable());
    }

    /**
     * Link the referenced item as a blocker of, or blocked by, the viewed item.
     */
    public function addDependency(): void
    {
        $item = $this->dependable();
        $this->authorize('manageDependencies', $item);

        $this->validate([
            'dependencyReference' => ['required', 'string'],
            'dependencyDirection' => ['required', 'in:'.implode(',', RelationshipType::keywords())],
        ]);

        $related = ReferenceResolver::commentable(trim($this->dependencyReference));

        if (! $related instanceof Task) {
            $this->addError('dependencyReference', __('No task found for that reference.'));

            return;
        }

        if (Gate::denies('view', $related)) {
            $this->addError('dependencyReference', __('You do not have access to that item.'));

            return;
        }

        [$type, $asSubject] = RelationshipType::requireKeyword($this->dependencyDirection);

        try {
            $item->addRelationship($related, $type, $asSubject);
        } catch (InvalidArgumentException) {
            $this->addError('dependencyReference', __('That would make an item depend on itself or create a cycle.'));

            return;
        }

        $item->recordDependencyChange(true, $this->dependencyDirection, $related->reference);

        $this->reset('dependencyReference');
        unset($this->relationshipGroups, $this->isBlocked);

        Flux::toast(variant: 'success', text: __('Dependency added.'));
    }

    /**
     * Remove a dependency link involving the viewed item.
     */
    public function removeDependency(int $dependencyId): void
    {
        $item = $this->dependable();
        $this->authorize('manageDependencies', $item);

        $dependency = Dependency::findOrFail($dependencyId);

        $morph = $item->getMorphClass();
        $itemIsDependent = $dependency->dependent_type === $morph && $dependency->dependent_id === $item->getKey();
        $itemIsBlocker = $dependency->blocker_type === $morph && $dependency->blocker_id === $item->getKey();

        abort_unless($itemIsDependent || $itemIsBlocker, 404);

        $related = $itemIsDependent ? $dependency->blocker : $dependency->dependent;

        abort_unless($related instanceof Task, 404);

        // The relationship keyword from this item's perspective — it is the
        // subject (outward) end when it is the blocker side of the link.
        $keyword = $dependency->type->keyword($itemIsBlocker);

        $dependency->delete();

        $item->recordDependencyChange(false, $keyword, $related->reference);

        unset($this->relationshipGroups, $this->isBlocked);

        Flux::toast(variant: 'success', text: __('Dependency removed.'));
    }
}
