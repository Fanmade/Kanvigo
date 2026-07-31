import { Extension, Mark, mergeAttributes } from '@tiptap/core';
import Mention from '@tiptap/extension-mention';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import Suggestion from '@tiptap/suggestion';

/**
 * @mention / #reference / [variable] support for the Flux (Tiptap) rich-text editor.
 *
 *   - `mention`   — a member, triggered by `@`, implemented as a *mark* over the
 *                   visible "@Name" text so its label can be shortened (trimming
 *                   trailing words keeps the link). Renders as
 *                   `<span class="mention" data-type="mention" data-id="…">@Name</span>`.
 *   - `reference` — a task, triggered by `#`, an atomic inline node rendered as a
 *                   link `<a class="reference" data-type="reference" data-id="…" href="/KAN-42">KAN-42</a>`.
 *   - `variable`  — a project variable, triggered by `[`, inserted as plain text
 *                   `[hero]`: a usage is text, not a node, so anything that can
 *                   type can write one.
 *
 * The suggestion list filters the project's members and tasks, fetched on demand
 * from the `data-mentionables-url` endpoint on the editor wrapper the first time a
 * `@` or `#` is typed (then cached per editor). The server re-derives mentions
 * from the saved `data-id`s, so anything typed here is validated there.
 */

/** The editor's wrapper element, which carries the suggestion endpoint and labels. */
function hostOf(editor) {
    return editor?.options?.element?.closest?.('[data-mentionables-url]') ?? null;
}

/**
 * Lazily load and cache the suggestion dataset for a given editor instance from
 * its wrapper's `data-mentionables-url`. Returns empty lists when no endpoint is
 * present (editors without project context) or on failure, so suggestions degrade
 * to simply offering nothing.
 */
async function mentionablesFor(editor) {
    const host = hostOf(editor);
    const url = host?.getAttribute('data-mentionables-url');
    const empty = { users: [], tasks: [], docs: [], variables: [], canCreateVariables: false };

    if (!url) {
        return empty;
    }

    if (!host.__mentionables) {
        host.__mentionables = fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : empty))
            .then((data) => ({
                users: data.users ?? [],
                tasks: data.tasks ?? [],
                docs: data.docs ?? [],
                variables: data.variables ?? [],
                canCreateVariables: data.can_create_variables ?? false,
            }))
            .catch(() => empty);
    }

    return host.__mentionables;
}

/**
 * The `#` reference candidates for a query: the project's tasks first, then its
 * docs, each tagged with the item type the server needs to resolve the link.
 */
async function referenceCandidates(query, editor) {
    const { tasks, docs } = await mentionablesFor(editor);
    const needle = query.toLowerCase();

    return [
        ...tasks.map((task) => ({ ...task, itemType: 'task' })),
        ...docs.map((doc) => ({ ...doc, itemType: 'doc' })),
    ]
        .filter(
            (item) =>
                item.reference.toLowerCase().includes(needle) ||
                item.title.toLowerCase().includes(needle),
        )
        .slice(0, 8);
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
    let editor = null;

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
        editor = props.editor;
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
            row.innerHTML = renderRow(item, editor);
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

const mentionSuggestionKey = new PluginKey('mentionSuggestion');
const mentionInvariantKey = new PluginKey('mentionInvariant');

/**
 * The `@` member-mention.
 *
 * Implemented as a mark over the visible "@Name" text (not an atomic node) so the
 * label is ordinary editable text: deleting trailing words shortens the label
 * while the mark — and its `data-id` — stays anchored to the same user. It still
 * renders as the `<span class="mention" data-type="mention" data-id>` the server
 * already parses, so storage and display are unchanged.
 *
 * Invariant: a mention is valid only while its text starts with `@`. The
 * appendTransaction below strips the mark from any run that no longer begins with
 * `@` (e.g. its leading token was deleted), so a gutted mention cleanly becomes
 * plain text instead of a half-broken node.
 */
const MentionMark = Mark.create({
    name: 'mention',
    inclusive: false,

    addAttributes() {
        return {
            id: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-id'),
                renderHTML: (attributes) => (attributes.id ? { 'data-id': attributes.id } : {}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-type="mention"]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes({ class: 'mention', 'data-type': 'mention' }, HTMLAttributes), 0];
    },

    addProseMirrorPlugins() {
        const markType = this.type;

        return [
            Suggestion({
                editor: this.editor,
                char: '@',
                pluginKey: mentionSuggestionKey,
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
                            {
                                type: 'text',
                                text: `@${props.name}`,
                                marks: [{ type: 'mention', attrs: { id: props.id } }],
                            },
                            { type: 'text', text: ' ' },
                        ])
                        .run();
                },
                render: () => suggestionRenderer((user) => escapeHtml(user.name)),
            }),

            new Plugin({
                key: mentionInvariantKey,
                appendTransaction: (transactions, oldState, newState) => {
                    if (!transactions.some((transaction) => transaction.docChanged)) {
                        return null;
                    }

                    let tr = null;

                    newState.doc.descendants((node, pos) => {
                        if (!node.isText || !node.marks.some((mark) => mark.type === markType)) {
                            return;
                        }

                        if (!node.text.startsWith('@')) {
                            tr = tr ?? newState.tr;
                            tr.removeMark(pos, pos + node.nodeSize, markType);
                        }
                    });

                    return tr;
                },
            }),
        ];
    },
});

/**
 * The `#` reference node — a task or a doc — rendered as a relative link to the
 * referenced item (`/KAN-42`, `/KAN-D3`). It reuses the Mention machinery but
 * renders an anchor (so it is a real link everywhere the content is shown)
 * instead of a span.
 *
 * `data-item-type` records which kind the id belongs to, so the server can
 * resolve the link back to its item and maintain the backlink. It is absent on
 * references written before docs existed, which are read as tasks.
 */
const ReferenceNode = Mention.extend({
    name: 'reference',

    addAttributes() {
        return {
            ...this.parent?.(),
            itemType: {
                default: 'task',
                parseHTML: (element) => element.getAttribute('data-item-type') || 'task',
                renderHTML: (attributes) => ({ 'data-item-type': attributes.itemType ?? 'task' }),
            },
        };
    },

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
                'data-item-type': node.attrs.itemType ?? 'task',
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
        items: ({ query, editor }) => referenceCandidates(query, editor),
        command: ({ editor, range, props }) => {
            editor
                .chain()
                .focus()
                .insertContentAt(range, [
                    {
                        type: 'reference',
                        attrs: { id: props.id, label: props.reference, itemType: props.itemType ?? 'task' },
                    },
                    { type: 'text', text: ' ' },
                ])
                .run();
        },
        render: () =>
            suggestionRenderer(
                (item) =>
                    `<span class="mention-suggestion-ref">${escapeHtml(item.reference)}</span> ${escapeHtml(item.title)}`,
            ),
    },
});

const variableSuggestionKey = new PluginKey('variableSuggestion');

/**
 * A complete variable name: at least two characters, starting with a letter.
 * Mirrors Variable::NAME_PATTERN — the picker must never offer to create a name
 * the server would reject.
 */
const VARIABLE_NAME = /^[a-z][a-z0-9_-]+$/;

/** A label the server rendered onto the editor host, or a plain fallback. */
function hostLabel(editor, attribute, fallback) {
    return hostOf(editor)?.getAttribute(attribute) ?? fallback;
}

/**
 * The `[` picker candidates: the project's variables matching the query, plus —
 * for someone who may manage them — an offer to define the typed name. The offer
 * appears whenever no variable has exactly that name, so a query that merely
 * prefixes an existing one can still create its own.
 */
async function variableCandidates(query, editor) {
    const { variables, canCreateVariables } = await mentionablesFor(editor);
    const needle = query.toLowerCase();

    const matches = variables
        .filter(
            (variable) =>
                variable.name.includes(needle) ||
                (variable.value ?? '').toLowerCase().includes(needle),
        )
        .slice(0, 8);

    const exists = variables.some((variable) => variable.name === needle);

    if (canCreateVariables && !exists && VARIABLE_NAME.test(needle)) {
        matches.push({ name: needle, create: true });
    }

    return matches;
}

/**
 * Where a "Create variable…" pick is waiting to come back to: the editor and the
 * range the typed `[name` occupies, so the finished variable lands exactly where
 * it was asked for. One at a time — the dialog is modal.
 */
let pendingVariable = null;

function requestVariable(editor, range, name) {
    pendingVariable = { editor, range, host: hostOf(editor) };

    window.Livewire?.dispatch('create-variable', { name });
}

/** Replace the typed `[name` with the finished usage — plain text, not a node. */
function insertUsage(editor, range, name) {
    editor.chain().focus().insertContentAt(range, [{ type: 'text', text: `[${name}]` }]).run();
}

document.addEventListener('livewire:init', () => {
    window.Livewire.on('variable-created', (payload) => {
        const pending = pendingVariable;
        pendingVariable = null;

        if (!pending) {
            return;
        }

        // The cached dataset predates the new variable; drop it so the next `[`
        // offers it.
        delete pending.host.__mentionables;

        insertUsage(pending.editor, pending.range, payload?.name);
    });
});

/**
 * The `[variable]` picker.
 *
 * A usage is plain text — `[hero]`, not an atomic node — because that is exactly
 * what the server parses and what an agent writing through MCP can produce. The
 * editor therefore always shows the raw name and never the value: you are editing
 * the source, and substituted text would let the cursor land inside a marker you
 * cannot see.
 *
 * Nothing is intercepted at save time. Typing or pasting a name no variable
 * defines saves fine and renders as unset — writing before you have decided is
 * the point of the feature, not an error to block.
 */
const VariableSuggestion = Extension.create({
    name: 'variableSuggestion',

    addProseMirrorPlugins() {
        return [
            Suggestion({
                editor: this.editor,
                char: '[',
                pluginKey: variableSuggestionKey,
                allowSpaces: false,
                items: ({ query, editor }) => variableCandidates(query, editor),
                command: ({ editor, range, props }) => {
                    if (props.create) {
                        requestVariable(editor, range, props.name);

                        return;
                    }

                    insertUsage(editor, range, props.name);
                },
                render: () =>
                    suggestionRenderer((item, editor) => {
                        const name = `<span class="mention-suggestion-ref">[${escapeHtml(item.name)}]</span>`;

                        if (item.create) {
                            return `${name} ${escapeHtml(hostLabel(editor, 'data-variable-create-label', 'Create variable…'))}`;
                        }

                        return `${name} ${escapeHtml(item.value ?? hostLabel(editor, 'data-variable-unset-label', 'No value yet'))}`;
                    }),
            }),
        ];
    },
});

export const mentionExtensions = [MentionMark, ReferenceNode, VariableSuggestion];
