<?php

namespace App\Livewire\Notes;

use App\Concerns\ManagesNotes;
use App\Models\Note;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The dedicated Notes management page: every note the user owns, with create,
 * edit, convert-to-task, visibility and delete actions. Create/edit reuse the
 * globally-mounted CreateNoteModal (dispatch `open-create-note`); convert reuses
 * the task dialog (dispatch `open-create-task` with `fromNoteId`); delete and
 * visibility come from the shared {@see ManagesNotes} concern.
 */
#[Title('Notes')]
class NoteList extends Component
{
    use ManagesNotes;

    /**
     * The user's own notes, newest first. Empty-title drafts left behind by an
     * abandoned note dialog are hidden.
     *
     * @return EloquentCollection<int, Note>
     */
    #[Computed]
    public function notes(): EloquentCollection
    {
        return Auth::user()->notes()
            ->where('title', '!=', '')
            ->with(['project', 'convertedTask.project'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('position')
            ->get();
    }

    protected function forgetNotes(): void
    {
        unset($this->notes);
    }

    public function render(): View
    {
        return view('livewire.notes.note-list');
    }
}
