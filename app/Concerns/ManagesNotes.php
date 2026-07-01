<?php

namespace App\Concerns;

use App\Models\Note;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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

        Flux::toast(text: __('Note deleted.'), variant: 'success');
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
            text: $note->is_public ? __('Note shared with the project.') : __('Note made private.'),
            variant: 'success',
        );
    }

    /**
     * Pin or unpin one of the viewer's own notes. Pinned notes sort to the top of
     * the list (KAN-319).
     */
    public function togglePin(int $noteId): void
    {
        $note = Auth::user()->notes()->findOrFail($noteId);
        $this->authorize('update', $note);

        $note->update(['is_pinned' => ! $note->is_pinned]);

        $this->forgetNotes();
    }

    /**
     * Move one of the viewer's own notes up one slot in the list (KAN-320).
     */
    public function moveNoteUp(int $noteId): void
    {
        $this->swapNoteWithNeighbour($noteId, -1);
    }

    /**
     * Move one of the viewer's own notes down one slot in the list.
     */
    public function moveNoteDown(int $noteId): void
    {
        $this->swapNoteWithNeighbour($noteId, 1);
    }

    /**
     * Swap a note's position with the neighbour $offset steps away in the current
     * order, persisting both. Reordering stays within a pin group — pinned notes
     * always sort above the rest — so it's a no-op at a group boundary or the
     * ends of the list.
     */
    protected function swapNoteWithNeighbour(int $noteId, int $offset): void
    {
        $note = Auth::user()->notes()->findOrFail($noteId);
        $this->authorize('update', $note);

        $ordered = $this->orderedOwnNotes();
        $index = $ordered->search(static fn (Note $candidate): bool => $candidate->id === $noteId);

        if ($index === false) {
            return;
        }

        $current = $ordered->get($index);
        $neighbour = $ordered->get($index + $offset);

        if ($neighbour === null || $neighbour->is_pinned !== $current->is_pinned) {
            return;
        }

        [$current->position, $neighbour->position] = [$neighbour->position, $current->position];
        $current->save();
        $neighbour->save();

        $this->forgetNotes();
    }

    /**
     * The viewer's own notes in display order (pinned first, then by position),
     * matching the list — the basis for resolving a note's neighbour.
     *
     * @return Collection<int, Note>
     */
    protected function orderedOwnNotes(): Collection
    {
        return Auth::user()->notes()
            ->where('title', '!=', '')
            ->orderByDesc('is_pinned')
            ->orderByDesc('position')
            ->get();
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
