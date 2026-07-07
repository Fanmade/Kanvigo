<?php

namespace App\Http\Controllers\OAuth;

use App\Models\McpClientGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approves an OAuth authorization request like Passport's stock controller,
 * additionally persisting the project scope the user picked on the consent
 * screen as a {@see McpClientGrant} for the client/user pair. Registered over
 * Passport's `passport.authorizations.approve` route in routes/web.php.
 *
 * The parent's approve() cannot be called around the grant logic because
 * getAuthRequestFromSession() pulls (consumes) the session state, so the small
 * completion sequence is inlined here.
 */
class ApproveMcpAuthorizationController extends ApproveAuthorizationController
{
    /**
     * Approve the authorization request, recording the chosen project scope.
     */
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        // Validate before touching the session: a validation failure redirects
        // back to the consent screen with the authorization request intact.
        $validated = $request->validate([
            'project_scope' => ['required', 'in:all,selected'],
            'projects' => ['array'],
            'projects.*' => ['integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $projectIds = $validated['project_scope'] === 'selected'
            ? $this->allowedSelectedProjectIds($user, $validated['projects'] ?? [])
            : [];

        $authRequest = $this->getAuthRequestFromSession($request);

        $authRequest->setAuthorizationApproved(true);

        $grant = McpClientGrant::query()->updateOrCreate(
            ['oauth_client_id' => $authRequest->getClient()->getIdentifier(), 'user_id' => $user->id],
            ['restricts_projects' => $validated['project_scope'] === 'selected'],
        );

        $grant->projects()->sync($projectIds);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), $authRequest->getGrantTypeId() === 'implicit');
    }

    /**
     * The ids of the selected projects the user is actually a member of,
     * validating that the restricted scope is not empty. Selections outside
     * the user's memberships are rejected rather than silently dropped.
     *
     * @param  array<int, int|string>  $selected
     * @return array<int, int>
     */
    protected function allowedSelectedProjectIds(User $user, array $selected): array
    {
        $memberProjectIds = $user->projects()
            ->whereIn('projects.id', $selected)
            ->pluck('projects.id');

        if ($memberProjectIds->isEmpty() || $memberProjectIds->count() !== count(array_unique($selected))) {
            throw ValidationException::withMessages([
                'projects' => __('Select at least one of your projects.'),
            ]);
        }

        return $memberProjectIds->all();
    }
}
