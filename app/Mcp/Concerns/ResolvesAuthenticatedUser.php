<?php

namespace App\Mcp\Concerns;

use App\Models\User;
use Laravel\Mcp\Request;
use RuntimeException;

trait ResolvesAuthenticatedUser
{
    /**
     * The authenticated user as the concrete model. A tool only ever runs for an
     * authenticated token, so this narrows the request's user type honestly.
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The MCP request is not authenticated.');
        }

        return $user;
    }
}
