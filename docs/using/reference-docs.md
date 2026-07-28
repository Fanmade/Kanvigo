# Reference docs

Reference docs are a project's knowledge pages — specs, decisions, conventions and
background that outlive any single task. Unlike a task a doc has no status and is
never "done"; unlike a [quick note](quick-notes.md) it belongs to a project and
inherits that project's access.

## What it does

Open a project and click **Docs** (or `⌘K` → the doc's reference) to reach the doc
index. It lists every doc you may see as the tree they are organized into, with a
search across titles and tags.

A doc has a title, a rich-text body — the same editor used for descriptions,
including inline images and file attachments — and lives at a readable URL: the
project short name, `-D` and a per-project number, e.g. `/ABC-D3`. That `ABC-D3`
reference is how a doc is addressed everywhere: in the command palette, in an
inline reference, and through the API.

Docs **nest**: any doc can sit under another (up to five levels), and a doc page
lists the docs nested directly under it, with a breadcrumb back up the tree. Move a
doc elsewhere by picking a different parent while editing.

## Drafts and publishing

A new doc starts as a **draft**: only members who may edit docs can see it, so an
unfinished page never reads as project knowledge. Publish it from the doc page
(the Visibility control in the rail) and it becomes visible to everyone who can see
the project; taking it back to draft hides it again.

Drafts are held back consistently, not just on the page: they are absent from the
doc index, the command palette, the API and the MCP tools for anyone who may not
edit docs — and a link pointing at a draft is left out of the lists below rather
than disclosing its title.

## Cross-links and backlinks

Docs and tasks link to each other. Type `#` in any description, doc body or comment
and pick a task or doc from the autocomplete; it is stored as a live link to that
item, clickable wherever the text is shown, with a hover card previewing what it
points at.

A reference written in a **task description or a doc body** also **links the two
items**: the doc page shows what it points at (Links, in the rail) and a
**Referenced by** section under the body listing every task and doc whose text
cites it. Task pages carry the same pair in their rail. Remove the reference from
the text and the link goes away with it — these links follow the writing.

A reference typed in a **comment** stays a plain link: comments are part of a
discussion rather than the item's own text, so they don't add to these lists.

Links made deliberately through the API or the MCP tools are kept separately: they
are curated, so editing either item's text never removes them.

References are pure navigation. Unlike a [dependency](../README.md#features) a
reference never blocks anything and may point in both directions at once.

## Permissions

Doc access is granted per project role, alongside the other project permissions:

| Permission   | Allows                                                      |
| ------------ | ----------------------------------------------------------- |
| `view-project` | Reading published docs                                    |
| `create-doc` | Creating docs                                               |
| `edit-doc`   | Editing docs, changing their parent, publishing them — and seeing drafts |
| `delete-doc` | Deleting docs                                               |

The default roles carry them as you would expect: members, admins and owners may
create and edit docs; viewers read published ones only. Custom roles can grant any
subset — a role with `create-doc` but not `edit-doc` may add docs without seeing
other people's drafts.

Deleting a doc is reversible in the sense that matters here: the docs nested under
it are kept and simply read as top-level while it is gone.

## AI / MCP

The MCP server exposes doc tools — `list-docs`, `get-doc`, `create-doc` and
`update-doc` — plus `add-reference` and `remove-reference` for cross-links between
any two tasks or docs. Docs are addressed by their `PROJ-D3` reference, and
`get-task`/`get-doc` both report `references` and `referenced_by`. Reading works
with any token; creating or editing needs a write-access token and the matching
project permission.

## REST API

The [REST API](api.md) covers the same ground under `/api/v1`:

| Method   | Path                                    | Description                        |
| -------- | --------------------------------------- | ---------------------------------- |
| `GET`    | `/projects/{short_name}/docs`           | The project's docs (paginated)     |
| `POST`   | `/projects/{short_name}/docs`           | Create a doc                       |
| `GET`    | `/docs/{reference}`                     | A single doc with body and links   |
| `PATCH`  | `/docs/{reference}`                     | Update title, body, parent, publish |
| `DELETE` | `/docs/{reference}`                     | Delete a doc                       |
| `POST`   | `/docs/{reference}/references`          | Cross-link to a task or doc        |
| `DELETE` | `/docs/{reference}/references/{related}` | Remove that cross-link            |

## Trying it out

A locally seeded install (`php artisan db:seed`) comes with a small doc tree in the
demo project: a published **Team handbook** with two nested pages — one of them
citing a demo task, so its **Referenced by** section is populated — and one draft,
to show the draft/published split.
