<?php

namespace App\Mcp\Concerns;

use App\Enums\TokenAbility;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresWriteAccess
{
    /**
     * The single scope Laravel MCP's OAuth layer issues (see Mcp::oauthRoutes).
     * An OAuth token is an interactive, user-approved delegation without a
     * read/write split, so it carries full write access.
     */
    private const string OAUTH_SCOPE = 'mcp:use';

    /**
     * Return an error response when the request's token lacks write access,
     * or null when the action may proceed.
     */
    protected function denyWithoutWriteAccess(Request $request): ?Response
    {
        if ($request->user()->tokenCan(TokenAbility::Write->value)) {
            return null;
        }

        if ($request->user()->tokenCan(self::OAUTH_SCOPE)) {
            return null;
        }

        return Response::error('This action requires an API token with write access. The token used for this request is read-only.');
    }
}
