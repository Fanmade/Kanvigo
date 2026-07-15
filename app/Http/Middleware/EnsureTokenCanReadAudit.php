<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\TokenAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the audit-event stream on two independent checks, because it exposes the
 * whole instance's activity — every user and project — not just the token
 * owner's data like the rest of the v1 API.
 *
 * 1. The acting user must hold the account-level {@see Permission::ManageUsers}
 *    permission, so only an instance operator can read the feed at all.
 * 2. The token must carry the dedicated `audit` ability, so an operator's
 *    ordinary read/write token cannot reach it — the audit scope has to be
 *    minted deliberately.
 */
class EnsureTokenCanReadAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->can(Permission::ManageUsers->value),
            403,
            __('Reading the audit event stream requires the manage-users permission.'),
        );

        abort_unless(
            $user->tokenCan(TokenAbility::Audit->value),
            403,
            __('This action requires a token with audit access.'),
        );

        return $next($request);
    }
}
