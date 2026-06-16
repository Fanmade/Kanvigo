# ToDo

## Images
- Add images to projects, including the logo/icon.
- Allow setting profile picture.

## MCP Server
MCP support at `/mcp`. Agents are authorized by a user via a personal API token
(read-only or read & write) and act in that user's name, restricted to projects
they can access. Done:
- Read access to projects, stories and tasks (any token).
- Creating projects (requires the "create-projects" permission), stories and tasks.
- Managing stories and tasks (title, description, task status).
- Adding comments to projects, stories and tasks.

Write tools require a token with the `write` ability.

# E-Mail updates
- Add optional e-mail updates on the project, story and task level.
- Setting for immediate e-mail updates or for specific time intervals.

## Stories from e-mails
- Add stories from e-mails.
- Support for attachments.

## Command palette
- Add a command palette with keyboard shortcuts and quick access.
- Include search functionality.

## Due dates
- Add optional due dates to stories and tasks.

## Prettier drag-and-drop
- Add prettier drag-and-drop.

## Add user administration
- Add user administration.
- Maybe an admin permission?

## Add @mentions

## Add tagging tasks and stories in descriptions and comments

## Improve visuals
- The project looks a little bit ugly.
- Maybe Claude Design can help?

## Add proper documentation

## Improve user assignment UI
- Add a "assign to me" button.
- Currently, there is a full-width multi-select for selecting users. To see who is assigned to a task, you have to open the multi-select. Consider using a more compact and intuitive UI for displaying assigned users.

## Improve the readme
- Add the logo.
- Add a screenshot.

## Improve the seed data
- Replace the lorem impsum with something more realistic (and fun). Include markdown formatting for the descriptions.
