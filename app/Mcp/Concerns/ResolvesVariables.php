<?php

namespace App\Mcp\Concerns;

use App\Models\Project;
use App\Models\Variable;
use App\Support\ReferenceResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Resolves the project and variable a variable write tool acts on, with the
 * `manage-variables` permission checked once, in one place.
 */
trait ResolvesVariables
{
    use ResolvesAuthenticatedUser;

    /**
     * The project the caller may manage variables in, or an error response. A
     * project the caller cannot see and one they may not manage variables in are
     * reported the same way, so the tools never confirm a project exists to
     * someone without access to it.
     */
    protected function resolveVariableProject(Request $request, string $shortName): Project|Response
    {
        $project = ReferenceResolver::project($shortName);
        $user = $this->authenticatedUser($request);

        if ($project === null || ! $user->can('view', $project)) {
            return Response::error('No project with short_name "'.$shortName.'" exists, or you do not have access to it. References look like "PROJ".');
        }

        if (! $user->can('manage-variables', $project)) {
            return Response::error('You do not have permission to manage variables in "'.$project->short_name.'".');
        }

        return $project;
    }

    /**
     * One of the project's variables, by name, or an error response naming what
     * is actually defined there.
     */
    protected function resolveVariable(Project $project, string $name): Variable|Response
    {
        $variable = $project->variables()->where('name', Variable::normalizeName($name))->first();

        if ($variable === null) {
            return Response::error('No variable named "'.$name.'" exists in "'.$project->short_name.'". Use the list-variables tool to see the project\'s variables, or create-variable to add one.');
        }

        return $variable;
    }
}
