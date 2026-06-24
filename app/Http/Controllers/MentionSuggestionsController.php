<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Queries\MentionSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MentionSuggestionsController extends Controller
{
    /**
     * The @mention / #reference autocomplete dataset for a project — its members
     * and open tasks. Fetched by the rich-text editor the first time a `@` or `#`
     * is typed, so the data is loaded on demand rather than embedded in the page.
     */
    public function __invoke(string $short_name, MentionSuggestions $suggestions): JsonResponse
    {
        $project = Project::where('short_name', $short_name)->firstOrFail();

        Gate::authorize('view', $project);

        return response()->json($suggestions->handle($project));
    }
}
