# REST API

Kanvigo exposes a versioned REST API under `/api/v1` for building tools and
automations on top of the board. It mirrors what the MCP tools can do, scoped to
the projects the calling token's owner is a member of.

Interactive, always-up-to-date OpenAPI documentation (generated from the code by
[Scramble](https://scramble.dedoc.co)) is served at **`/docs/api`** — and the raw
OpenAPI document at **`/docs/api.json`**. Both are restricted to the local
environment by default; grant the `viewApiDocs` gate to expose them elsewhere.

## Authentication

Authenticate with a **personal access token** created under *Settings → API
tokens*, sent as a Bearer token:

```
Authorization: Bearer <token>
Accept: application/json
```

Tokens carry one of two ability levels:

- **Read-only** — may call the `GET` endpoints.
- **Read & write** — may also create and update (the `POST`/`PATCH` endpoints).

The MCP server at `/mcp` accepts the same personal access tokens, and
additionally supports **OAuth 2.1** for MCP clients that require it (e.g.
Claude Desktop): clients register dynamically and the user approves access in
the browser. An OAuth connection acts with the user's full read & write access,
optionally limited to selected projects on the consent screen — restricted
connections behave like restricted tokens (filtered listings, out-of-scope
references are `404`, no project creation). Connections are listed and revoked
under *Settings → API tokens*.

A token may additionally be **restricted to selected projects** when it is
created. A restricted token only sees and acts on its allowed projects —
out-of-scope references are `404`, listings are filtered, and creating new
projects is `403` — while an unrestricted token (the default) accesses
everything its owner can. The same restriction applies to the MCP server.

A request without a valid token is `401`. A read-only token calling a write
endpoint is `403`. A reference that does not exist *or* belongs to a project you
cannot see is `404` — the API never reveals the existence of others' data.
Validation failures are `422` with a standard `{ "message", "errors" }` body.
Requests are rate-limited to 60/minute per token.

## Endpoints

All paths are relative to `/api/v1`. References use the same scheme as the app:
a project short name (`PROJ`) and a flat task reference (`PROJ-42`).

| Method   | Path                                                  | Ability | Description |
| -------- | ----------------------------------------------------- | ------- | ----------- |
| `GET`    | `/user`                                               | read    | The authenticated token's user. |
| `GET`    | `/users/{id}`                                          | read    | A single user by their stable id. Name always; email only to shared-project members or admins. |
| `GET`    | `/projects`                                           | read    | Projects you belong to (paginated). |
| `POST`   | `/projects`                                           | write   | Create a project (you become its owner). |
| `GET`    | `/projects/{short_name}`                              | read    | A single project. |
| `PATCH`  | `/projects/{short_name}`                              | write   | Update a project (admins/owner). |
| `GET`    | `/projects/{short_name}/tasks`                        | read    | A project's tasks (paginated). Filters: `status`, `parent`. |
| `GET`    | `/projects/{short_name}/task-types`                   | read    | A project's configured task types. |
| `GET`    | `/projects/{short_name}/tags`                         | read    | A project's tags (with usage counts). |
| `POST`   | `/projects/{short_name}/tasks`                        | write   | Create a task. |
| `GET`    | `/tasks/{reference}`                                  | read    | A single task. |
| `PATCH`  | `/tasks/{reference}`                                  | write   | Update a task's fields, status, type or tags. |
| `POST`   | `/tasks/{reference}/cancel`                           | write   | Cancel a task (`cancel_reason` + optional `cancel_message`). |
| `POST`   | `/tasks/{reference}/reopen`                           | write   | Reopen a canceled task. |
| `PUT`    | `/tasks/{reference}/assignees`                        | write   | Replace a task's assignees (`assignee_ids`: the stable user ids from the task's `assignees`). |
| `POST`   | `/tasks/{reference}/dependencies`                     | write   | Link a dependency (`direction`: `blocked_by` / `blocks`). |
| `DELETE` | `/tasks/{reference}/dependencies/{related}`           | write   | Unlink a dependency. |
| `GET`    | `/projects/{short_name}/comments`                     | read    | A project's comments (paginated, threaded). |
| `POST`   | `/projects/{short_name}/comments`                     | write   | Comment on a project (`parent_id` to reply). |
| `GET`    | `/tasks/{reference}/comments`                         | read    | A task's comments (paginated, threaded). |
| `POST`   | `/tasks/{reference}/comments`                         | write   | Comment on a task (`parent_id` to reply). |
| `PATCH`  | `/comments/{id}`                                      | write   | Edit your own comment. |
| `DELETE` | `/comments/{id}`                                      | write   | Delete your own comment (tombstoned if it has replies). |
| `GET`    | `/notes`                                              | read    | Your notes (paginated). |
| `POST`   | `/notes`                                              | write   | Capture a note (optionally attach/publish to a project). |
| `GET`    | `/notes/{id}`                                         | read    | A single note (yours, or a public project note). |
| `PATCH`  | `/notes/{id}`                                         | write   | Update your own note. |
| `DELETE` | `/notes/{id}`                                         | write   | Delete your own note. |
| `POST`   | `/notes/{id}/convert`                                 | write   | Convert a note into a task (`project`, optional `parent`). |
| `GET`    | `/projects/{short_name}/attachments`                  | read    | A project's file attachments (paginated). |
| `POST`   | `/projects/{short_name}/attachments`                  | write   | Upload a file to a project (`file`). |
| `GET`    | `/tasks/{reference}/attachments`                      | read    | A task's file attachments (paginated). |
| `POST`   | `/tasks/{reference}/attachments`                      | write   | Upload a file to a task (`file`). |
| `GET`    | `/attachments/{id}`                                   | read    | Download an attachment's file. |
| `DELETE` | `/attachments/{id}`                                   | write   | Delete an attachment. |

Enum-valued fields follow the MCP conventions: `priority` and `cancel_reason` are
sent and returned **by name** (`High`, `WontFix`), `status` by its value
(`In progress`). Task `type` is set by its name. Users are referenced by a stable
`id` (the value carried on assignees and comment authors); resolve it through
`GET /users/{id}`. Paginated responses wrap the records in `data` alongside `links`
and `meta`.

## Example

```bash
# List your projects
curl -s https://your-app/api/v1/projects \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# Create a task
curl -s -X POST https://your-app/api/v1/projects/PROJ/tasks \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d title="Wire the webhook" -d priority=High -d status=ToDo
```
