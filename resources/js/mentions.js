import Mention from '@tiptap/extension-mention';

/**
 * @mention / #reference support for the Flux (Tiptap) rich-text editor.
 *
 * Two atomic inline nodes are added:
 *   - `mention`   — a member, triggered by `@`, rendered as
 *                   `<span class="mention" data-type="mention" data-id="…">@Name</span>`.
 *   - `reference` — a task, triggered by `#`, rendered as a link
 *                   `<a class="reference" data-type="reference" data-id="…" href="/KAN-42">KAN-42</a>`.
 *
 * The suggestion list filters the project's members and tasks, fetched on demand
 * from the `data-mentionables-url` endpoint on the editor wrapper the first time a
 * `@` or `#` is typed (then cached per editor). The server re-derives mentions
 * from the saved `data-id`s, so anything typed here is validated there.
 */

/**
 * Lazily load and cache the `{ users, tasks }` suggestion dataset for a given
 * editor instance from its wrapper's `data-mentionables-url`. Returns empty lists
 * when no endpoint is present (editors without project context) or on failure, so
 * suggestions degrade to simply offering nothing.
 */
async function mentionablesFor(editor) {
    const host = editor?.options?.element?.closest?.('[data-mentionables-url]');
    const url = host?.getAttribute('data-mentionables-url');

    if (!url) {
        return { users: [], tasks: [] };
    }

    if (!host.__mentionables) {
        host.__mentionables = fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : { users: [], tasks: [] }))
            .then((data) => ({ users: data.users ?? [], tasks: data.tasks ?? [] }))
            .catch(() => ({ users: [], tasks: [] }));
    }

    return host.__mentionables;
}

/**
 * A minimal caret-anchored suggestion popup (no extra dependency). It renders the
 * candidate rows, tracks a highlighted index, and drives selection by keyboard
 * (Up/Down/Enter/Esc) or click.
 */
function suggestionRenderer(renderRow) {
    let panel = null;
    let rows = [];
    let items = [];
    let highlighted = 0;
    let onSelect = null;

    const close = () => {
        panel?.remove();
        panel = null;
        rows = [];
        items = [];
        highlighted = 0;
    };

    const paint = () => {
        rows.forEach((row, index) => {
            row.classList.toggle('is-active', index === highlighted);
        });
    };

    const position = (clientRect) => {
        const rect = clientRect?.();

        if (!rect || !panel) {
            return;
        }

        panel.style.left = `${rect.left}px`;
        panel.style.top = `${rect.bottom + 4}px`;
    };

    const build = (props) => {
        items = props.items;
        onSelect = props.command;
        highlighted = 0;

        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'mention-suggestions';
            document.body.appendChild(panel);
        }

        panel.innerHTML = '';
        rows = items.map((item, index) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'mention-suggestion';
            row.innerHTML = renderRow(item);
            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                onSelect(item);
            });
            row.addEventListener('mouseenter', () => {
                highlighted = index;
                paint();
            });
            panel.appendChild(row);
            return row;
        });

        panel.style.display = items.length ? 'flex' : 'none';
        paint();
        position(props.clientRect);
    };

    return {
        onStart: build,
        onUpdate: build,
        onKeyDown(props) {
            if (!items.length) {
                return false;
            }

            switch (props.event.key) {
                case 'ArrowDown':
                    highlighted = (highlighted + 1) % items.length;
                    paint();
                    return true;
                case 'ArrowUp':
                    highlighted = (highlighted - 1 + items.length) % items.length;
                    paint();
                    return true;
                case 'Enter':
                    onSelect(items[highlighted]);
                    return true;
                case 'Escape':
                    close();
                    return true;
                default:
                    return false;
            }
        },
        onExit: close,
    };
}

const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);

/**
 * The `@` member-mention node, rendered as a styled span tagged with the user id.
 */
const MentionNode = Mention.extend({ name: 'mention' }).configure({
    HTMLAttributes: { class: 'mention' },
    renderText: ({ node }) => `@${node.attrs.label ?? node.attrs.id}`,
    suggestion: {
        char: '@',
        items: async ({ query, editor }) => {
            const { users } = await mentionablesFor(editor);
            const needle = query.toLowerCase();

            return users.filter((user) => user.name.toLowerCase().includes(needle)).slice(0, 8);
        },
        command: ({ editor, range, props }) => {
            editor
                .chain()
                .focus()
                .insertContentAt(range, [
                    { type: 'mention', attrs: { id: props.id, label: props.name } },
                    { type: 'text', text: ' ' },
                ])
                .run();
        },
        render: () => suggestionRenderer((user) => escapeHtml(user.name)),
    },
});

/**
 * The `#` task-reference node, rendered as a relative link to the task. It reuses
 * the Mention machinery but renders an anchor (so it is a real link everywhere the
 * content is shown) instead of a span.
 */
const ReferenceNode = Mention.extend({
    name: 'reference',

    parseHTML() {
        return [{ tag: 'a[data-type="reference"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        const reference = node.attrs.label ?? '';

        return [
            'a',
            {
                ...HTMLAttributes,
                class: 'reference',
                'data-type': 'reference',
                'data-id': node.attrs.id,
                'data-label': reference,
                href: `/${reference}`,
            },
            reference,
        ];
    },
}).configure({
    renderText: ({ node }) => node.attrs.label ?? '',
    suggestion: {
        char: '#',
        items: async ({ query, editor }) => {
            const { tasks } = await mentionablesFor(editor);
            const needle = query.toLowerCase();

            return tasks
                .filter(
                    (task) =>
                        task.reference.toLowerCase().includes(needle) ||
                        task.title.toLowerCase().includes(needle),
                )
                .slice(0, 8);
        },
        command: ({ editor, range, props }) => {
            editor
                .chain()
                .focus()
                .insertContentAt(range, [
                    { type: 'reference', attrs: { id: props.id, label: props.reference } },
                    { type: 'text', text: ' ' },
                ])
                .run();
        },
        render: () =>
            suggestionRenderer(
                (task) =>
                    `<span class="mention-suggestion-ref">${escapeHtml(task.reference)}</span> ${escapeHtml(task.title)}`,
            ),
    },
});

export const mentionExtensions = [MentionNode, ReferenceNode];
