<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    use ResolvesApiReferences;

    /**
     * List a project's tags, alphabetical, each with its task usage count.
     */
    public function index(string $short_name): AnonymousResourceCollection
    {
        $project = $this->resolveProjectOr404($short_name);

        // Returned in full (not paginated): a project's tag catalog is bounded by
        // configuration, and consumers want the whole set to populate pickers.
        return TagResource::collection(
            $project->tags()->withCount('tasks')->orderBy('name')->get(),
        );
    }
}
