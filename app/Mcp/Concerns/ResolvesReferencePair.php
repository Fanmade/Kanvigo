<?php

namespace App\Mcp\Concerns;

use App\Support\ReferencePairResolution;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the two ends of a cross-reference for the MCP link tools: the item
 * the link is written on (which the caller must be able to update) and the item
 * it points at (which the caller must at least be able to view). Either end may
 * be a task ("PROJ-42") or a doc ("PROJ-D3").
 */
trait ResolvesReferencePair
{
    /**
     * Resolve the changed item and the item it references from their references.
     *
     * The resolution carries an error {@see Response} when either reference is
     * malformed or the caller lacks the required access; otherwise it carries the
     * pair as [item, related].
     */
    protected function resolveReferencePair(Request $request, string $reference, string $relatedReference): ReferencePairResolution
    {
        $item = ReferenceResolver::referenceable($reference);

        if ($item === null) {
            return ReferencePairResolution::failure(Response::error('No task or doc with reference "'.$reference.'" exists. References look like "PROJ-42" (a task) or "PROJ-D3" (a doc).'));
        }

        if (! $request->user()->can('view', $item) || ! $request->user()->can('update', $item)) {
            return ReferencePairResolution::failure(Response::error('You do not have access to change the links of "'.$reference.'".'));
        }

        $related = ReferenceResolver::referenceable($relatedReference);

        if ($related === null || ! $request->user()->can('view', $related)) {
            return ReferencePairResolution::failure(Response::error('No task or doc with reference "'.$relatedReference.'" exists, or you do not have access to it. References look like "PROJ-42" (a task) or "PROJ-D3" (a doc).'));
        }

        return ReferencePairResolution::success($item, $related);
    }
}
