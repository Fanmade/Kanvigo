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

A request without a valid token is `401`. A read-only token calling a write
endpoint is `403`. A reference that does not exist *or* belongs to a project you
cannot see is `404` — the API never reveals the existence of others' data.
Validation failures are `422` with a standard `{ "message", "errors" }` body.
Requests are rate-limited to 60/minute per token.

## Endpoints

All paths are relative to `/api/v1`. References use the same scheme as the app:
a project short name (`PROJ`) and a flat task reference (`PROJ-42`).

| Method  | Path                                | Ability | Description |
| ------- | ----------------------------------- | ------- | ----------- |
| `GET`   | `/user`                             | read    | The authenticated token's user. |
| `GET`   | `/projects`                         | read    | Projects you belong to (paginated). |
| `GET`   | `/projects/{short_name}`            | read    | A single project. |
| `GET`   | `/projects/{short_name}/tasks`      | read    | A project's tasks (paginated). Filters: `status`, `parent`. |
| `POST`  | `/projects/{short_name}/tasks`      | write   | Create a task. |
| `GET`   | `/tasks/{reference}`                | read    | A single task. |
| `PATCH` | `/tasks/{reference}`                | write   | Update a task's fields, status, type or tags. |
| `POST`  | `/projects/{short_name}/comments`   | write   | Comment on a project. |
| `POST`  | `/tasks/{reference}/comments`       | write   | Comment on a task. |

Enum-valued fields follow the MCP conventions: `priority` and `cancel_reason` are
sent and returned **by name** (`High`, `WontFix`), `status` by its value
(`In progress`). Task `type` is set by its name. Paginated responses wrap the
records in `data` alongside `links` and `meta`.

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
