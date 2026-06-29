<?php

namespace App\Mcp\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the project and optional parent task a task-creating MCP tool needs
 * ({@see CreateTaskTool}, {@see ConvertNoteTool}). Each resolver returns an error
 * {@see Response} when the reference is missing, inaccessible, or — for a parent —
 * not in the target project; otherwise it returns the resolved model.
 */
trait ResolvesTaskCreationReferences
{
    /**
     * Resolve the project a task will be created in: it must exist and the caller
     * must hold create-task on it.
     */
    protected function resolveTaskProject(Request $request, string $shortName): Project|Response
    {
        $project = ReferenceResolver::project($shortName);

        if ($project === null || ! $request->user()->can('create-task', $project)) {
            return Response::error('No project with short_name "'.$shortName.'" exists, or you do not have access to it. References look like "PROJ".');
        }

        return $project;
    }

    /**
     * Resolve an optional parent task within the project. Returns null when no
     * parent reference was given (a top-level task), the resolved parent when it
     * is viewable and in the project, or an error Response otherwise.
     */
    protected function resolveParentTask(Request $request, ?string $reference, Project $project): Task|Response|null
    {
        if ($reference === null) {
            return null;
        }

        $parent = ReferenceResolver::task($reference);

        if ($parent === null || ! $request->user()->can('view', $parent)) {
            return Response::error('No task with reference "'.$reference.'" exists, or you do not have access to it. References look like "PROJ-42".');
        }

        if ($parent->project_id !== $project->id) {
            return Response::error('The parent task "'.$reference.'" is not in project "'.$project->short_name.'".');
        }

        return $parent;
    }
}
