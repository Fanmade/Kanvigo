<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks which surface a request came through ("mcp" or "api") for audit
 * attribution. MCP and REST tokens are both plain Sanctum PATs, so the
 * route group is the only thing that can tell them apart — unmarked
 * requests are direct web-session (UI) actions.
 */
class SetAuditSource
{
    public function handle(Request $request, Closure $next, string $source): Response
    {
        Context::addHidden('audit.source', $source);

        return $next($request);
    }
}
