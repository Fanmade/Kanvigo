<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\VariableResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VariableController extends Controller
{
    use ResolvesApiReferences;

    /**
     * List a project's variables, alphabetical.
     */
    public function index(string $short_name): AnonymousResourceCollection
    {
        $project = $this->resolveProjectOr404($short_name);

        // Returned in full (not paginated): a project's vocabulary is small and
        // consumers want the whole set to resolve the "[name]" markers they read.
        return VariableResource::collection($project->variables()->get());
    }
}
