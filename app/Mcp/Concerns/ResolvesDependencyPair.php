<?php

namespace App\Mcp\Concerns;

use App\Models\Story;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the two ends of a dependency link from their references for the MCP
 * write tools: the item being changed (which the user must be able to update)
 * and the related item (which the user must at least be able to view). Only
 * stories and tasks can take part in a dependency.
 */
trait ResolvesDependencyPair
{
    /**
     * Resolve the changed item and the related item from their references.
     *
     * Returns an error {@see Response} when either reference is malformed, is not
     * a story or task, or the user lacks the required access; otherwise returns
     * the pair as [item, related].
     *
     * @return array{0: Story|Task, 1: Story|Task}|Response
     */
    protected function resolveDependencyPair(Request $request, string $reference, string $relatedReference): array|Response
    {
        $item = ReferenceResolver::commentable($reference);

        if (! $item instanceof Story && ! $item instanceof Task) {
            return Response::error('No story or task with reference "'.$reference.'" exists. Dependencies link stories and tasks; references look like "PROJ1" or "PROJ1-3".');
        }

        if (! $request->user()->can('update', $item)) {
            return Response::error('You do not have access to change the dependencies of "'.$reference.'".');
        }

        $related = ReferenceResolver::commentable($relatedReference);

        if (! $related instanceof Story && ! $related instanceof Task) {
            return Response::error('No story or task with reference "'.$relatedReference.'" exists. Dependencies link stories and tasks; references look like "PROJ1" or "PROJ1-3".');
        }

        if (! $request->user()->can('view', $related)) {
            return Response::error('You do not have access to "'.$relatedReference.'".');
        }

        return [$item, $related];
    }
}
