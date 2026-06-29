<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskTypeController extends Controller
{
    use ResolvesApiReferences;

    /**
     * List a project's configured task types, in display order.
     */
    public function index(string $short_name): AnonymousResourceCollection
    {
        $project = $this->resolveProjectOr404($short_name);

        // Returned in full (not paginated): a project's task-type set is bounded
        // by configuration, and consumers want the whole set to populate pickers.
        return TaskTypeResource::collection($project->taskTypes()->get());
    }
}
