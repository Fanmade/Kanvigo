@props(['intervalMs'])

{{-- Drives auto-refresh for the task page: every interval, while "Live updates"
     is on, it dispatches a Livewire event the task header, comments and activity
     feed listen for. Skips a tick whenever the user is focused in a field or
     rich-text editor, so an in-progress comment/description draft is never
     morphed away (the editor syncs deferred, so unsaved keystrokes would be lost
     by a refresh). wire:ignore keeps the timer alive across re-renders. --}}
<div
    wire:ignore
    data-test="task-page-refresh"
    x-data="{
        timer: null,
        init() {
            this.timer = setInterval(() => {
                if (! this.$wire.liveUpdates) return;
                const el = document.activeElement;
                if (el && (el.isContentEditable || el.matches('input, textarea, select'))) return;
                this.$wire.dispatch('task-page-refresh');
            }, {{ (int) $intervalMs }});
        },
        destroy() {
            if (this.timer) {
                clearInterval(this.timer);
            }
        },
    }"
></div>
