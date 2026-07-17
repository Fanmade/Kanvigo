<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AccessAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Facades\Audit;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * The user the access token belongs to. The caller always sees their own
     * contact details.
     */
    public function current(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Fetch a single user by their public id. Resolving the user requires sharing
     * the collaboration boundary ({@see UserPolicy::view()}) — a
     * stranger 404s rather than being disclosed — and the email field is gated
     * further by {@see UserPolicy::viewContactInfo()}.
     */
    public function show(Request $request, User $user): UserResource
    {
        $viewer = $request->user();

        // Resolvable by anyone within the collaboration boundary, plus user
        // administrators (who see every account); the email field is gated again
        // inside the resource. A stranger 404s rather than being disclosed.
        abort_if($viewer->cannot('view', $user) && $viewer->cannot('viewContactInfo', $user), 404);

        // Audit only the disclosure of another member's contact info — seeing
        // your own is not "who looked at whom" and would be high-volume noise.
        if ($user->isNot($viewer) && $viewer->can('viewContactInfo', $user)) {
            Audit::record(AccessAudit::contactInfoViewed($user));
        }

        return new UserResource($user);
    }
}
