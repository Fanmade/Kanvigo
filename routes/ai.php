<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetAuditSource;
use App\Mcp\Servers\KanvigoServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth 2.1 for MCP clients that require it (e.g. Claude Desktop): discovery
// metadata, dynamic client registration (RFC 7591) and the mcp:use scope,
// backed by Passport. Static Sanctum bearer tokens keep working — the auth
// middleware tries the sanctum guard first, then Passport's api guard.
Mcp::oauthRoutes();

Mcp::web('/mcp', KanvigoServer::class)->middleware(['auth:sanctum,api', 'throttle:mcp', EnsureUserIsActive::class, SetAuditSource::class.':mcp']);
