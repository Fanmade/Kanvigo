<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Resolve API references (project short_names, task references) to their models,
 * applying the v1 leak-safe authorization policy: a reference that does not exist
 * and one the caller may not see both 404, so existence is never disclosed. Pass
 * a stronger ability to gate on it while keeping that 404 (e.g. `update` on a
 * task); a separate 403 for "can view but not act" stays at the call site.
 */
trait ResolvesApiReferences
{
    /**
     * Resolve a project by short_name, 404ing when it does not exist or the
     * caller lacks the given ability on it.
     */
    protected function resolveProjectOr404(string $shortName, string $ability = 'view'): Project
    {
        $project = ReferenceResolver::project($shortName);

        abort_if($project === null || Auth::user()->cannot($ability, $project), 404);

        return $project;
    }

    /**
     * Resolve a task by reference, 404ing when it does not exist or the caller
     * lacks the given ability on it.
     */
    protected function resolveTaskOr404(string $reference, string $ability = 'view'): Task
    {
        $task = ReferenceResolver::task($reference);

        abort_if($task === null || Auth::user()->cannot($ability, $task), 404);

        return $task;
    }
}
