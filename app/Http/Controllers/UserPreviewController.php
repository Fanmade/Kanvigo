<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Queries\UserPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserPreviewController extends Controller
{
    /**
     * The compact preview of a user, fetched by the @mention hovercard when a
     * reader hovers a mention link. 403s when the reader may not see the user
     * (they share no project) and 404s for an unknown user, so the card degrades
     * gracefully. When the mention carries the project it lives in (the `project`
     * query parameter) and the reader can see that project, the user's role in it
     * is included.
     */
    public function __invoke(Request $request, User $user, UserPreview $preview): JsonResponse
    {
        Gate::authorize('view', $user);

        $project = null;
        $shortName = trim((string) $request->query('project', ''));

        if ($shortName !== '') {
            $project = Project::where('short_name', $shortName)->first();

            if ($project !== null && Gate::denies('view', $project)) {
                $project = null;
            }
        }

        return response()->json($preview->handle($user, $project));
    }
}
