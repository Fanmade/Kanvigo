<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskTypeResource;
use App\Support\ReferenceResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class TaskTypeController extends Controller
{
    /**
     * List a project's configured task types, in display order.
     */
    public function index(string $short_name): AnonymousResourceCollection
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        // Returned in full (not paginated): a project's task-type set is bounded
        // by configuration, and consumers want the whole set to populate pickers.
        return TaskTypeResource::collection($project->taskTypes()->get());
    }
}
