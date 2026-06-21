<?php

namespace App\Mcp\Concerns;

use App\Models\Task;
use App\Support\DependencyPairResolution;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the two ends of a dependency link from their references for the MCP
 * write tools: the task being changed (which the user must be able to update)
 * and the related task (which the user must at least be able to view). Only
 * tasks can take part in a dependency.
 */
trait ResolvesDependencyPair
{
    /**
     * Resolve the changed task and the related task from their references.
     *
     * The resolution carries an error {@see Response} when either reference is
     * malformed, is not a task, or the user lacks the required access; otherwise
     * it carries the pair as [item, related].
     */
    protected function resolveDependencyPair(Request $request, string $reference, string $relatedReference): DependencyPairResolution
    {
        $item = ReferenceResolver::task($reference);

        if (! $item instanceof Task) {
            return DependencyPairResolution::failure(Response::error('No task with reference "'.$reference.'" exists. Dependencies link tasks; references look like "PROJ-42".'));
        }

        if (! $request->user()->can('update', $item)) {
            return DependencyPairResolution::failure(Response::error('You do not have access to change the dependencies of "'.$reference.'".'));
        }

        $related = ReferenceResolver::task($relatedReference);

        if (! $related instanceof Task) {
            return DependencyPairResolution::failure(Response::error('No task with reference "'.$relatedReference.'" exists. Dependencies link tasks; references look like "PROJ-42".'));
        }

        if (! $request->user()->can('view', $related)) {
            return DependencyPairResolution::failure(Response::error('You do not have access to "'.$relatedReference.'".'));
        }

        return DependencyPairResolution::success($item, $related);
    }
}
