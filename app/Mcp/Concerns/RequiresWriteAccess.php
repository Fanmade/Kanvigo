<?php

namespace App\Mcp\Concerns;

use App\Enums\TokenAbility;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresWriteAccess
{
    /**
     * Return an error response when the request's token lacks write access,
     * or null when the action may proceed.
     */
    protected function denyWithoutWriteAccess(Request $request): ?Response
    {
        if ($request->user()->tokenCan(TokenAbility::Write->value)) {
            return null;
        }

        return Response::error('This action requires an API token with write access. The token used for this request is read-only.');
    }
}
