import Sortable from 'sortablejs';

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
     * Backs the "Add tag" control on the story and task views. It keeps a local
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
});
