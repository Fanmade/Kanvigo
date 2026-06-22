<?php

namespace App\Concerns;

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Owner-side note actions (delete, toggle visibility) shared by the views that
 * render a note list — the dashboard panel and the project Notes section. Every
 * action is scoped to the viewer's own notes, so a member viewing someone else's
 * public note can read it but never act on it.
 */
trait ManagesNotes
{
    /**
     * Delete one of the viewer's own notes.
     */
    public function deleteNote(int $noteId): void
    {
        $note = Auth::user()->notes()->findOrFail($noteId);
        $this->authorize('delete', $note);

        $note->delete();

        $this->forgetNotes();

        Flux::toast(variant: 'success', text: __('Note deleted.'));
    }

    /**
     * Toggle whether one of the viewer's own attached notes is public to its
     * project. A projectless note can't be made public (the model enforces it).
     */
    public function toggleNoteVisibility(int $noteId): void
    {
        $note = Auth::user()->notes()->findOrFail($noteId);
        $this->authorize('changeVisibility', $note);

        if ($note->project_id === null) {
            return;
        }

        $note->update(['is_public' => ! $note->is_public]);

        $this->forgetNotes();

        Flux::toast(
            variant: 'success',
            text: $note->is_public ? __('Note shared with the project.') : __('Note made private.'),
        );
    }

    /**
     * Refresh the note list after the dialog saves one.
     */
    #[On('note-saved')]
    public function refreshNotes(): void
    {
        $this->forgetNotes();
    }

    /**
     * Drop the cached note list so it re-reads after a change.
     */
    abstract protected function forgetNotes(): void;
}
