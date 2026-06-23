import { mergeAttributes } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Sortable from 'sortablejs';

/**
 * The Tiptap Image node, extended so an inline image can link to its full-size
 * original: it carries an optional `href` and renders as
 * `<a href target="_blank"><img></a>`. This keeps the "click the thumbnail to
 * open the full image" behaviour and lets migrated `<a><img></a>` content
 * round-trip through the editor without losing the link.
 */
const LinkedImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            href: {
                default: null,
                parseHTML: (element) => element.closest('a')?.getAttribute('href') ?? null,
                // The href belongs on the wrapping <a>, not the <img>.
                renderHTML: () => ({}),
            },
        };
    },

    renderHTML({ node, HTMLAttributes }) {
        const img = ['img', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes)];

        return node.attrs.href
            ? ['a', { href: node.attrs.href, target: '_blank', rel: 'noopener noreferrer' }, img]
            : img;
    },
});

/**
 * Add inline image support to Flux's rich-text editor.
 *
 * Flux's editor ships without a Tiptap Image extension, so it would otherwise
 * drop <img> nodes on load (losing inline images when a description is edited).
 * We register the Image extension and stash the Tiptap instance on the editor
 * element so the upload wrapper (Alpine `richEditor`) can insert images at the
 * cursor after they finish uploading.
 */
document.addEventListener('flux:editor', (e) => {
    e.detail.registerExtensions([
        LinkedImage.configure({ HTMLAttributes: { class: 'rounded-lg' } }),
    ]);

    e.detail.init(({ editor }) => {
        // Stash the Tiptap instance on the editor's root element so the upload
        // wrapper can find it. editor.options.element is the mounted content
        // node; its [data-flux-editor] ancestor is the element the wrapper sees.
        const root = editor.options.element?.closest?.('[data-flux-editor]');

        if (root) {
            root.__editor = editor;
        }
    });
});

/**
 * Walk the siblings of a card in the given direction and return the id of the
 * nearest neighbouring task card, or null if there is none.
 */
function adjacentTaskId(card, direction) {
    let sibling = card[direction];

    while (sibling && !sibling.hasAttribute('data-task-id')) {
        sibling = sibling[direction];
    }

    return sibling ? parseInt(sibling.getAttribute('data-task-id'), 10) : null;
}

/**
 * Board drag-and-drop.
 *
 * Each column's task list (and each empty column's drop zone) registers an
 * `x-data="kanbanList"` Alpine component backed by SortableJS. All lists share
 * the `kanban` group so cards can be dragged within and across columns with
 * smooth FLIP animation, touch support and clear drop affordances. On drop the
 * card's status and its new neighbours are sent to the server via
 * `$wire.reorderTask`, which persists the order; Livewire's morph then
 * reconciles the authoritative result.
 *
 * Keyboard moves are handled separately by the per-card "Move to" menu in the
 * Blade view, since SortableJS does not provide keyboard interaction.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('kanbanList', () => ({
        sortable: null,

        init() {
            this.sortable = Sortable.create(this.$el, {
                group: 'kanban',
                draggable: '[data-task-card]',
                // Let interactive controls (the "Move to" menu) be clicked, not dragged.
                filter: '[data-no-drag]',
                preventOnFilter: false,
                animation: 160,
                easing: 'cubic-bezier(0.2, 0, 0, 1)',
                ghostClass: 'kanban-ghost',
                chosenClass: 'kanban-chosen',
                dragClass: 'kanban-drag',
                fallbackOnBody: true,
                // Hold briefly before dragging on touch so the list can still scroll.
                delay: 120,
                delayOnTouchOnly: true,
                touchStartThreshold: 6,
                onStart: () => document.body.classList.add('kanban-dragging'),
                onEnd: (event) => {
                    document.body.classList.remove('kanban-dragging');

                    const card = event.item;
                    const taskId = parseInt(card.getAttribute('data-task-id'), 10);
                    const toStatus = event.to.getAttribute('data-status');

                    if (!taskId || !toStatus) {
                        return;
                    }

                    this.$wire.reorderTask(
                        taskId,
                        toStatus,
                        adjacentTaskId(card, 'previousElementSibling'),
                        adjacentTaskId(card, 'nextElementSibling'),
                    );
                },
            });
        },

        destroy() {
            this.sortable?.destroy();
            this.sortable = null;
        },
    }));

    /**
     * Tag input widget.
     *
     * Backs the "Add tag" control on the task views. It keeps a local
     * `query`, filters the server-provided `suggestions` (most-used tags not yet
     * applied) and tracks a `highlighted` row so Up/Down/Enter navigate the list
     * with the keyboard. Picking a suggestion calls `$wire.addTag(name)`; when
     * the typed text matches no existing tag, the last row creates it by opening
     * the create-tag modal via `$wire.openTagModal(name)`.
     *
     * The server dispatches `tags-updated` after every change so the suggestion
     * list refreshes without closing the open input.
     */
    window.Alpine.data('tagInput', ({ suggestions, createPrefix }) => ({
        adding: false,
        query: '',
        highlighted: 0,
        suggestions,
        createPrefix,

        open() {
            this.adding = true;
            this.reset();
            this.$nextTick(() => this.$refs.input?.focus());
        },

        reset() {
            this.query = '';
            this.highlighted = 0;
        },

        normalized() {
            return this.query.trim().toLowerCase();
        },

        filtered() {
            const q = this.normalized();

            return this.suggestions.filter((tag) => tag.name.toLowerCase().includes(q));
        },

        canCreate() {
            const q = this.normalized();

            return q !== '' && !this.suggestions.some((tag) => tag.name.toLowerCase() === q);
        },

        rowCount() {
            return this.filtered().length + (this.canCreate() ? 1 : 0);
        },

        createLabel() {
            return `${this.createPrefix} “${this.query.trim()}”`;
        },

        move(direction) {
            const max = this.rowCount() - 1;

            if (max < 0) {
                return;
            }

            this.highlighted = Math.min(Math.max(this.highlighted + direction, 0), max);
        },

        choose() {
            const list = this.filtered();

            if (this.highlighted < list.length) {
                this.add(list[this.highlighted].name);
            } else if (this.canCreate()) {
                this.createNew();
            }
        },

        add(name) {
            this.$wire.addTag(name);
            this.reset();
            this.$nextTick(() => this.$refs.input?.focus());
        },

        createNew() {
            this.$wire.openTagModal(this.query.trim());
            this.adding = false;
        },

        dotClass(color) {
            return {
                red: 'bg-red-500',
                orange: 'bg-orange-500',
                amber: 'bg-amber-500',
                yellow: 'bg-yellow-500',
                lime: 'bg-lime-500',
                green: 'bg-green-500',
                emerald: 'bg-emerald-500',
                teal: 'bg-teal-500',
                cyan: 'bg-cyan-500',
                sky: 'bg-sky-500',
                blue: 'bg-blue-500',
                indigo: 'bg-indigo-500',
                violet: 'bg-violet-500',
                purple: 'bg-purple-500',
                fuchsia: 'bg-fuchsia-500',
                pink: 'bg-pink-500',
                rose: 'bg-rose-500',
            }[color] ?? 'bg-zinc-400';
        },
    }));

    /**
     * Inline image uploads for the Flux rich-text editor.
     *
     * Wraps a `flux:editor` and, on paste or drop of an image, uploads it as an
     * inline attachment via `$wire.upload('inlineImage', …)` then inserts the
     * stored image at the cursor using the Tiptap instance captured in the
     * `flux:editor` listener above.
     */
    window.Alpine.data('richEditor', () => ({
        uploading: false,

        imageFiles(list) {
            return Array.from(list || []).filter((file) => file.type.startsWith('image/'));
        },

        /**
         * Extract image files from a paste/drop's DataTransfer.
         *
         * Desktop browsers expose pasted images on `.files`, but mobile browsers
         * (e.g. Android Chrome) leave `.files` empty and only populate `.items`,
         * where each file must be unwrapped with `getAsFile()`. Try `.files`
         * first, then fall back to `.items` so paste works on both.
         */
        filesFrom(dataTransfer) {
            if (!dataTransfer) {
                return [];
            }

            const fromFiles = this.imageFiles(dataTransfer.files);

            if (fromFiles.length) {
                return fromFiles;
            }

            const fromItems = Array.from(dataTransfer.items || [])
                .filter((item) => item.kind === 'file')
                .map((item) => item.getAsFile())
                .filter(Boolean);

            return this.imageFiles(fromItems);
        },

        handlePaste(event) {
            const files = this.filesFrom(event.clipboardData);

            if (!files.length) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.handle(files);
        },

        handleDrop(event) {
            const files = this.filesFrom(event.dataTransfer);

            if (files.length) {
                event.stopPropagation();
                this.handle(files);
            }
        },

        editor() {
            return this.$el.querySelector('[data-flux-editor]')?.__editor ?? null;
        },

        async embed(file) {
            this.uploading = true;

            await new Promise((resolve) => {
                this.$wire.upload(
                    'inlineImage',
                    file,
                    () => this.$wire.addInlineImage().then((image) => {
                        if (image?.src) {
                            this.editor()?.chain().focus()
                                .setImage({ src: image.src, href: image.href ?? null })
                                .run();
                        }

                        resolve();
                    }),
                    () => resolve(),
                );
            });

            this.uploading = false;
        },

        async handle(list) {
            for (const file of this.imageFiles(list)) {
                await this.embed(file);
            }
        },
    }));
});
