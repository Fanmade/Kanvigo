<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetAuditSource;
use App\Mcp\Servers\KanvigoServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', KanvigoServer::class)->middleware(['auth:sanctum', 'throttle:mcp', EnsureUserIsActive::class, SetAuditSource::class.':mcp']);
