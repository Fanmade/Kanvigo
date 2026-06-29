<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateProject;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    use ResolvesApiReferences;

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
     * Create a project, making the caller its owner and seeding the default task
     * types. Requires write access and the create-projects permission.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('create', Project::class), 403);

        $request->merge(['short_name' => strtoupper(trim((string) $request->input('short_name')))]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_name' => Project::shortNameRules(),
            'description' => ['nullable', 'string'],
        ]);

        $project = app(CreateProject::class)->handle(
            Auth::user(),
            $validated['title'],
            $validated['short_name'],
            $validated['description'] ?? null,
        );

        $project->loadCount('rootTasks');

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    /**
     * Show a single project by its short name. Returns 404 — rather than 403 —
     * when the project does not exist or belongs to projects the user cannot
     * see, so the API never leaks the existence of others' projects.
     */
    public function show(string $short_name): ProjectResource
    {
        $project = $this->resolveProjectOr404($short_name);

        $project->loadCount(['rootTasks', 'comments']);

        return new ProjectResource($project);
    }

    /**
     * Update a project's title, short name and/or description. Restricted to
     * members who can manage the project's settings (admins and the owner).
     */
    public function update(Request $request, string $short_name): ProjectResource
    {
        $project = $this->resolveProjectOr404($short_name);
        abort_if(Auth::user()->cannot('manageSettings', $project), 403);

        if ($request->has('short_name')) {
            $request->merge(['short_name' => strtoupper(trim((string) $request->input('short_name')))]);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'short_name' => ['sometimes', ...Project::shortNameRules($project->id)],
            'description' => ['nullable', 'string'],
        ]);

        $updates = [];

        foreach (['title', 'short_name', 'description'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $validated[$field] ?? null;
            }
        }

        if ($updates !== []) {
            $project->update($updates);
        }

        $project->refresh()->loadCount('rootTasks');

        return new ProjectResource($project);
    }
}
