<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Support\ReferenceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;

class TaskController extends Controller
{
    /**
     * List a project's tasks, paginated, optionally filtered by status and/or
     * restricted to the direct subtasks of a parent task.
     */
    public function index(Request $request, string $short_name): AnonymousResourceCollection
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        $validated = $request->validate([
            'status' => ['nullable', new Enum(Status::class)],
            'parent' => ['nullable', 'string'],
        ]);

        $parentId = null;

        if (isset($validated['parent'])) {
            $parent = ReferenceResolver::task($validated['parent']);

            abort_if(
                $parent === null || $parent->project_id !== $project->id || Auth::user()->cannot('view', $parent),
                404,
            );

            $parentId = $parent->id;
        }

        $tasks = $project->tasks()
            ->with(['tags', 'project', 'taskType', 'dependencyLinks.blocker'])
            ->when(isset($validated['status']), fn ($query) => $query->where('status', Status::from($validated['status'])))
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('task_number')
            ->paginate();

        return TaskResource::collection($tasks);
    }

    /**
     * Show a single task by its reference (e.g. "PROJ-42"). 404s when it does not
     * exist or belongs to a project the user cannot see.
     */
    public function show(string $reference): TaskResource
    {
        $task = ReferenceResolver::task($reference);

        abort_if($task === null || Auth::user()->cannot('view', $task), 404);

        $task->loadMissing(['tags', 'project', 'parent', 'taskType', 'dependencyLinks.blocker']);

        return new TaskResource($task);
    }
}
