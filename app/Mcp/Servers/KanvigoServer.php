<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AddDependencyTool;
use App\Mcp\Tools\ConvertNoteTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetAttachmentTool;
use App\Mcp\Tools\GetNoteTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RemoveDependencyTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Kanvigo')]
#[Version('0.1.0')]
#[Instructions(<<<'TEXT'
    Kanvigo is a project-management board. The data model is a hierarchy:

    - A project groups work and has a short_name (2-4 uppercase letters), title and description.
      A project is referenced by its short_name, e.g. "PROJ".
    - A project contains tasks. A task is referenced by its project's short_name plus a
      project-wide task number, e.g. "PROJ-42". Each task has a status: one of "Planned", "ToDo",
      "In progress", "Done" or "Canceled".
    - Tasks can nest: a task may have subtasks, which are themselves tasks (referenced the same
      flat way, e.g. "PROJ-43"). A task without a parent is a top-level task in its project.

    You act as the authenticated user and can only ever see or change data for projects the user
    is a member of; tasks inherit access from their project. If a project or task does not exist
    or the user cannot access it, the tool returns an error.

    A task can be canceled (abandoned with a reason) rather than deleted. The update-task tool
    accepts a "cancel_reason" (one of "WontFix", "Duplicate", "Deprecated") with an optional
    "cancel_message" to cancel a task — which also cancels its open subtasks — and "reopen": true
    to reopen a canceled task back to "Planned". Cancellation is a terminal state distinct from the
    working statuses, so it is not set through the "status" field. The get-task and list-tasks tools
    report a canceled task's "cancel_reason" (and get-task its "cancel_message").

    Tasks can depend on each other: a task may be "blocked by" the tasks it depends on (its
    blockers) and may itself "block" others. The get-task tool reports a task's "blocked_by" and
    "blocks" references plus an "is_blocked" flag (true while a blocker is not yet complete); the
    list-tasks tool includes the "is_blocked" flag. Use the add-dependency tool to link tasks
    (direction "blocked_by": reference is blocked by related_reference; "blocks": reference blocks
    related_reference) and the remove-dependency tool to unlink them. Self-dependencies and cycles
    are rejected.

    Task and project descriptions and comment bodies are HTML, restricted to a small allow-list:
    headings, bold/italic, lists, links, blockquotes, code, and inline images (rendered as a
    thumbnail linking to the full-size image). The get tools return this content as HTML, and the
    create/update/comment tools expect it as HTML — whatever you send is sanitized to that
    allow-list, so unsupported tags are dropped.

    Projects and tasks may have file attachments, including images embedded inline in their
    descriptions. The get tools list each attachment's id; pass that id to the get-attachment
    tool to retrieve the file's content (images and audio are returned as viewable content).

    Projects and tasks can also carry a discussion thread. The get tools (not the list tools)
    return a "comments" array, oldest first; each comment has an id, author name, body,
    created-at timestamp and the parent_id of the comment it replies to (null for a top-level
    comment). A deleted comment is kept as a tombstone with an empty body and "is_deleted": true
    when it still has replies. Use the add-comment tool to post a new comment.

    You also have personal notes: quick captures owned by you, outside the "PROJ-N" namespace and
    referenced by a plain numeric id. A note has a title and an optional HTML body, and may be
    attached to a project; an attached note can be made public so that project's members can read
    it (a note can only be public while it is attached to a project). Use create-note, list-notes
    (your own notes plus public notes in projects you are a member of), get-note (returns the body),
    and update-note (change the title/body, attach/detach a project, or set public — setting public
    requires the note to be attached to a project). convert-note turns a note into a task in a
    project you choose, keeping the note and linking it to the task it produced; the note then
    reports that task under "converted_task".

    Read tools (list/get) are available to any token. Write tools (create/update/comment, link or
    unlink dependencies, and the note create/update/convert tools) require a token with write
    access and return an error for read-only tokens. Creating a project also requires the
    "create-projects" permission.
    TEXT)]
class KanvigoServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        GetProjectTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        GetAttachmentTool::class,
        CreateProjectTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        AddCommentTool::class,
        AddDependencyTool::class,
        RemoveDependencyTool::class,
        ListNotesTool::class,
        GetNoteTool::class,
        CreateNoteTool::class,
        UpdateNoteTool::class,
        ConvertNoteTool::class,
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
