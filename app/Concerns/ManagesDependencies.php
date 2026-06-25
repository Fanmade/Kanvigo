<?php

namespace App\Concerns;

use App\Contracts\Dependable;
use App\Models\Dependency;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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
     * The dependency links where the viewed item is blocked, with the blocking
     * item (and the relations needed to render its reference) eager-loaded.
     *
     * @return Collection<int, Dependency>
     */
    #[Computed]
    public function blockerLinks(): Collection
    {
        return $this->dependable()->dependencyLinks()->with('blocker')->get();
    }

    /**
     * The dependency links where the viewed item is the blocker, with the blocked
     * item eager-loaded.
     *
     * @return Collection<int, Dependency>
     */
    #[Computed]
    public function blockingLinks(): Collection
    {
        return $this->dependable()->dependentLinks()->with('dependent')->get();
    }

    /**
     * The blocker links whose blocking item still exists, ready to render.
     *
     * @return BaseCollection<int, Dependency>
     */
    #[Computed]
    public function presentBlockerLinks(): BaseCollection
    {
        return $this->blockerLinks()->filter(static fn (Dependency $link): bool => $link->blocker !== null)->values();
    }

    /**
     * The blocking links whose dependent item still exists, ready to render.
     *
     * @return BaseCollection<int, Dependency>
     */
    #[Computed]
    public function presentBlockingLinks(): BaseCollection
    {
        return $this->blockingLinks()->filter(static fn (Dependency $link): bool => $link->dependent !== null)->values();
    }

    /**
     * Whether the viewed item has an unfinished blocker.
     */
    #[Computed]
    public function isBlocked(): bool
    {
        return $this->blockerLinks()->contains(
            static fn (Dependency $link): bool => $link->blocker instanceof Dependable && ! $link->blocker->isComplete()
        );
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
        $this->authorize('update', $item);

        $this->validate([
            'dependencyReference' => ['required', 'string'],
            'dependencyDirection' => ['required', 'in:blocked_by,blocks'],
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

        // "blocked_by": the viewed item depends on the related one. "blocks":
        // the related item depends on the viewed one.
        [$dependent, $blocker] = $this->dependencyDirection === 'blocks'
            ? [$related, $item]
            : [$item, $related];

        try {
            $dependent->addBlocker($blocker);
        } catch (InvalidArgumentException) {
            $this->addError('dependencyReference', __('That would make an item depend on itself or create a cycle.'));

            return;
        }

        $direction = $this->dependencyDirection === 'blocks' ? 'blocks' : 'blocked_by';
        $item->recordDependencyChange(true, $direction, $related->reference);

        $this->reset('dependencyReference');
        unset($this->blockerLinks, $this->blockingLinks, $this->presentBlockerLinks, $this->presentBlockingLinks, $this->isBlocked);

        Flux::toast(variant: 'success', text: __('Dependency added.'));
    }

    /**
     * Remove a dependency link involving the viewed item.
     */
    public function removeDependency(int $dependencyId): void
    {
        $item = $this->dependable();
        $this->authorize('update', $item);

        $dependency = Dependency::findOrFail($dependencyId);

        $morph = $item->getMorphClass();
        $itemIsDependent = $dependency->dependent_type === $morph && $dependency->dependent_id === $item->getKey();
        $itemIsBlocker = $dependency->blocker_type === $morph && $dependency->blocker_id === $item->getKey();

        abort_unless($itemIsDependent || $itemIsBlocker, 404);

        // From the item's perspective: as the dependent it is "blocked_by" its
        // blocker; as the blocker it "blocks" its dependent.
        $direction = $itemIsDependent ? 'blocked_by' : 'blocks';
        $related = $itemIsDependent ? $dependency->blocker : $dependency->dependent;

        abort_unless($related instanceof Task, 404);

        $dependency->delete();

        $item->recordDependencyChange(false, $direction, $related->reference);

        unset($this->blockerLinks, $this->blockingLinks, $this->presentBlockerLinks, $this->presentBlockingLinks, $this->isBlocked);

        Flux::toast(variant: 'success', text: __('Dependency removed.'));
    }
}
