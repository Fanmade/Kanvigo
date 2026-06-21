<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Locked;

/**
 * Shared morph-subject resolution for Livewire components that act on a single
 * Project or Task chosen at mount (comments, activity feed, subscription
 * toggle).
 *
 * Holds the locked type/id pair and resolves it back to the model, re-authorizing
 * `view` on every read so a tampered identifier cannot disclose another project's
 * data. The `#[Locked]` attributes live here so all consumers share the guard.
 */
trait ResolvesMorphSubject
{
    #[Locked]
    public string $morphSubjectType;

    #[Locked]
    public int $morphSubjectId;

    /**
     * Record the morph subject's type and id and authorize the initial view.
     */
    protected function initMorphSubject(Project|Task $subject): void
    {
        $this->morphSubjectType = $subject->getMorphClass();
        $this->morphSubjectId = $subject->getKey();

        $this->authorize('view', $subject);
    }

    /**
     * Resolve the morph subject back to its model, re-authorizing `view`.
     */
    protected function resolveMorphSubject(): Project|Task
    {
        $class = Relation::getMorphedModel($this->morphSubjectType) ?? $this->morphSubjectType;

        $subject = match ($class) {
            Project::class => Project::findOrFail($this->morphSubjectId),
            Task::class => Task::findOrFail($this->morphSubjectId),
            default => abort(404),
        };

        $this->authorize('view', $subject);

        return $subject;
    }
}
