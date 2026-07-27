<?php

namespace App\Mcp\Concerns;

use App\Models\Doc;
use App\Models\Project;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the references the doc tools take: a doc by its "PROJ-D<n>" reference,
 * the project a doc is created in, and the optional parent a doc is nested under.
 * Each resolver returns an error {@see Response} when the reference is unknown or
 * the caller lacks the access it needs; otherwise it returns the resolved model.
 */
trait ResolvesDocReferences
{
    /**
     * Resolve a doc the caller must be able to perform the given ability on
     * ("view" to read it, "update" to change it).
     */
    protected function resolveDoc(Request $request, string $reference, string $ability = 'view'): Doc|Response
    {
        $doc = ReferenceResolver::doc($reference);

        if ($doc === null || ! $request->user()->can('view', $doc)) {
            return Response::error('No doc with reference "'.$reference.'" exists, or you do not have access to it. Doc references look like "PROJ-D3".');
        }

        if (! $request->user()->can($ability, $doc)) {
            return Response::error('You do not have access to change "'.$doc->reference.'".');
        }

        return $doc;
    }

    /**
     * Resolve the project a doc will be created in: it must exist and the caller
     * must hold create-doc on it.
     */
    protected function resolveDocProject(Request $request, string $shortName): Project|Response
    {
        $project = ReferenceResolver::project($shortName);

        if ($project === null || ! $request->user()->can('view', $project)) {
            return Response::error('No project with short_name "'.$shortName.'" exists, or you do not have access to it. References look like "PROJ".');
        }

        if (! $request->user()->can('create-doc', $project)) {
            return Response::error('You do not have access to create docs in project "'.$project->short_name.'".');
        }

        return $project;
    }

    /**
     * Resolve an optional parent doc within the project. Returns null when no
     * parent reference was given (a top-level doc), the resolved parent when it is
     * viewable and in the project, or an error Response otherwise.
     */
    protected function resolveParentDoc(Request $request, ?string $reference, Project $project): Doc|Response|null
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        $parent = $this->resolveDoc($request, $reference);

        if ($parent instanceof Response) {
            return $parent;
        }

        if ($parent->project_id !== $project->id) {
            return Response::error('The parent doc "'.$parent->reference.'" is not in project "'.$project->short_name.'".');
        }

        return $parent;
    }
}
