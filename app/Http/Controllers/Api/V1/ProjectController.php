<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Support\ReferenceResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    /**
     * List the projects the authenticated user is a member of, paginated.
     */
    public function index(): AnonymousResourceCollection
    {
        $projects = Auth::user()->projects()
            ->withCount('rootTasks')
            ->orderBy('title')
            ->paginate();

        return ProjectResource::collection($projects);
    }

    /**
     * Show a single project by its short name. Returns 404 — rather than 403 —
     * when the project does not exist or belongs to projects the user cannot
     * see, so the API never leaks the existence of others' projects.
     */
    public function show(string $short_name): ProjectResource
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        $project->loadCount('rootTasks');

        return new ProjectResource($project);
    }
}
