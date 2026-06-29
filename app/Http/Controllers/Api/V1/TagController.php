<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Support\ReferenceResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{
    /**
     * List a project's tags, alphabetical, each with its task usage count.
     */
    public function index(string $short_name): AnonymousResourceCollection
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        // Returned in full (not paginated): a project's tag catalog is bounded by
        // configuration, and consumers want the whole set to populate pickers.
        return TagResource::collection(
            $project->tags()->withCount('tasks')->orderBy('name')->get(),
        );
    }
}
