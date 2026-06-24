<?php

namespace App\Http\Controllers;

use App\Queries\TaskPreview;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TaskPreviewController extends Controller
{
    /**
     * The compact preview of a task, fetched by the #reference hovercard when a
     * reader hovers a reference link. 404s for an unknown reference and 403s when
     * the reader cannot see the task, so the card degrades gracefully.
     */
    public function __invoke(string $short_name, int $task_number, TaskPreview $preview): JsonResponse
    {
        $task = ReferenceResolver::task($short_name.'-'.$task_number);

        abort_if($task === null, 404);

        Gate::authorize('view', $task);

        return response()->json($preview->handle($task));
    }
}
