<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AddDependencyTool;
use App\Mcp\Tools\AddReferenceTool;
use App\Mcp\Tools\ConvertNoteTool;
use App\Mcp\Tools\CreateDocTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\CreateVariableTool;
use App\Mcp\Tools\FindUsersTool;
use App\Mcp\Tools\GetAttachmentTool;
use App\Mcp\Tools\GetCurrentUserTool;
use App\Mcp\Tools\GetDocTool;
use App\Mcp\Tools\GetNoteTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\GetUserTool;
use App\Mcp\Tools\ListDocsTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListVariablesTool;
use App\Mcp\Tools\RemoveDependencyTool;
use App\Mcp\Tools\RemoveReferenceTool;
use App\Mcp\Tools\SetAssigneesTool;
use App\Mcp\Tools\UpdateDocTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\UpdateVariableTool;
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

    A reference like "PROJ-42" carries no domain. Whenever you link to a project, task or doc, use
    the "url" every tool returns alongside it — the list tools, the get tools and the create/update
    tools all carry one. It is the absolute address on this instance. Never build a link from a
    reference and a guessed domain: this instance is self-hosted and its address is not derivable
    from the reference.

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

    A project also holds reference docs: statusless knowledge pages (specs, decisions, background)
    that live beside the tasks. A doc is referenced by its project's short_name, "-D" and a
    project-wide doc number, e.g. "PROJ-D3", and has a title, an optional HTML body, tags and
    attachments. Docs nest into a tree like tasks (a doc reports its "parent" and the docs nested
    under it). A doc is a draft until it is published: a draft is visible only to members who may
    edit docs, a published doc to everyone who can see the project. Use list-docs (the project's
    docs, optionally only those under a "parent"), get-doc (returns the body and links), create-doc
    and update-doc (change the title, body, parent or published flag).

    Tasks and docs can also be cross-referenced: a plain link between two items, in any combination
    of tasks and docs. A reference is pure navigation — unlike a dependency it never blocks anything
    and may be circular. The get-task and get-doc tools report "references" (what the item links to)
    and "referenced_by" (its backlinks). Use add-reference and remove-reference to curate links.
    Links can also be written inline in an item's text as a reference to another item; those follow
    the text, so they reappear when re-saved and are removed by editing the text — the tools only
    manage curated links.

    Task and project descriptions, doc bodies and comment bodies are HTML, restricted to a small allow-list:
    headings, bold/italic, lists, links, blockquotes, code, and inline images (rendered as a
    thumbnail linking to the full-size image). The get tools return this content as HTML, and the
    create/update/comment tools expect it as HTML — whatever you send is sanitized to that
    allow-list, so unsupported tags are dropped.

    Projects, tasks and docs may have file attachments, including images embedded inline in their
    descriptions or bodies. The get tools list each attachment's id; pass that id to the
    get-attachment tool to retrieve the file's content (images and audio are returned as viewable
    content).

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

    Users are referenced by a stable "id" wherever they appear (task assignees, comment authors).
    Pass that id to the get-user tool to resolve the person's name and — when you share a project
    with them or hold user-administration access — their email. You can only resolve users you share
    a project with. To assign work to a person you first need their id: use get-current-user to get
    your own id ("assign this to me"), and find-users to look someone up by a name or email fragment
    among the people you share a project with ("assign this to Dana"). Then use set-assignees to set
    a task's assignees by those ids (an absolute set — pass every id that should be assigned, or an
    empty list to clear); only project members can be assigned.

    The add-comment tool can post a reply by passing "reply_to" with the id of the comment to answer;
    replies stay one level deep, so replying to a reply attaches to the root comment.

    By default list-tasks, list-docs, get-project and list-notes return every item, so you get the full
    project context in one call. For very large projects you can page instead: pass a "limit" to cap how
    many tasks (docs, notes) come back; the response then carries a "page" object (for get-project, "tasks_page")
    with "has_more" and a "next_cursor" you pass back as "cursor" to fetch the next page. Without a limit
    "has_more" is always false — nothing is ever truncated silently.

    A project can define variables: named stand-ins for facts that recur or are not decided yet,
    written in prose as "[name]" — e.g. "[main_protagonist]" showing "Robin Hood" wherever it
    appears. The stored text always keeps the literal "[name]"; changing the value changes every
    place at once. get-task and get-doc therefore return the body exactly as stored plus a
    "variables" sidecar listing the variables that content uses and their current values: resolve
    the names against it when reading, and leave "[name]" untouched when writing the body back,
    or you would bake in a value and delete the usage. A variable with a null value is undecided,
    not broken — that is the point during early planning, and it renders as its own name.

    Use list-variables to see a project's vocabulary, create-variable to add one, and
    update-variable to change what it stands for or to rename it. Writing "[name]" in a description,
    doc body or comment never creates a variable — a mistyped name would otherwise become permanent
    project vocabulary — so call create-variable explicitly when a name should become part of it.
    Renaming rewrites every usage in the project's content; update-variable reports how many items
    it changed under "usages_rewritten". There is no delete tool for variables: deleting one
    silently changes what existing documents show.

    Read tools (list/get) are available to any token. Write tools (create/update/comment, link or
    unlink dependencies and references, the doc create/update tools, and the note
    create/update/convert tools) require a token with write access and return an error for read-only
    tokens. Creating a project also requires the "create-projects" permission, creating or
    editing a doc the project's "create-doc"/"edit-doc" permission, and creating or updating a
    variable its "manage-variables" permission.
    TEXT)]
class KanvigoServer extends Server
{
    /**
     * How many tools a single tools/list page carries. Raised above the package
     * default (15) so the whole toolset — tasks, docs, references, notes and the
     * user lookups — is advertised in one page: a client that does not follow the
     * pagination cursor would otherwise never see the tools at the end of the list.
     */
    public int $defaultPaginationLength = 50;

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
        GetUserTool::class,
        GetCurrentUserTool::class,
        FindUsersTool::class,
        GetAttachmentTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        AddCommentTool::class,
        SetAssigneesTool::class,
        AddDependencyTool::class,
        RemoveDependencyTool::class,
        ListDocsTool::class,
        GetDocTool::class,
        CreateDocTool::class,
        UpdateDocTool::class,
        AddReferenceTool::class,
        RemoveReferenceTool::class,
        ListNotesTool::class,
        GetNoteTool::class,
        CreateNoteTool::class,
        UpdateNoteTool::class,
        ConvertNoteTool::class,
        ListVariablesTool::class,
        CreateVariableTool::class,
        UpdateVariableTool::class,
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
