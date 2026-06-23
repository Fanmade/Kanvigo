<?php

use App\Mcp\Servers\KanvigoServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', KanvigoServer::class)->middleware(['auth:sanctum', 'throttle:mcp']);
