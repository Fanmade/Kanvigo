<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AddDependencyTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetAttachmentTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RemoveDependencyTool;
use App\Mcp\Tools\UpdateStoryTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Kanbrio')]
#[Version('0.1.0')]
#[Instructions(<<<'TEXT'
    Kanbrio is a project-management board. The data model is a hierarchy:

    - A project groups work and has a short_name (2-4 uppercase letters), title and description.
    - A project contains stories. A story is referenced by its project short_name plus its
      number, e.g. "PROJ1".
    - A story contains tasks. A task belongs to a story but is referenced by its project's
      short_name plus a project-wide task number, e.g. "PROJ-42". Each task has a status: one of
      "Planned", "ToDo", "In progress" or "Done".

    You act as the authenticated user and can only ever see or change data for projects the user
    is a member of; stories and tasks inherit access from their project. If a project, story or
    task does not exist or the user cannot access it, the tool returns an error.

    Stories and tasks can depend on each other: an item may be "blocked by" the items it depends
    on (its blockers) and may itself "block" others. The get tools report an item's "blocked_by"
    and "blocks" references plus an "is_blocked" flag (true while a blocker is not yet complete);
    the list tools include the "is_blocked" flag. Use the add-dependency tool to link items
    (direction "blocked_by": reference is blocked by related_reference; "blocks": reference blocks
    related_reference) and the remove-dependency tool to unlink them. Self-dependencies and cycles
    are rejected.

    Projects, stories and tasks may have file attachments, including images embedded inline in
    their descriptions. The get tools list each attachment's id; pass that id to the
    get-attachment tool to retrieve the file's content (images and audio are returned as
    viewable content).

    Read tools (list/get) are available to any token. Write tools (create/update/comment, link or
    unlink dependencies) require a token with write access and return an error for read-only
    tokens. Creating a project also requires the "create-projects" permission.
    TEXT)]
class KanbrioServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        GetProjectTool::class,
        ListStoriesTool::class,
        GetStoryTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        GetAttachmentTool::class,
        CreateProjectTool::class,
        CreateStoryTool::class,
        CreateTaskTool::class,
        UpdateStoryTool::class,
        UpdateTaskTool::class,
        AddCommentTool::class,
        AddDependencyTool::class,
        RemoveDependencyTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
