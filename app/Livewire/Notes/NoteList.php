<?php

namespace App\Livewire\Notes;

use App\Concerns\ManagesNotes;
use App\Models\Note;
use App\Models\Project;
use App\Support\GlobalSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The dedicated Notes management page: every note the user owns, with create,
 * edit, convert-to-task, visibility, pin, reorder and delete actions, plus
 * full-text search and a project filter. Create/edit reuse the globally-mounted
 * CreateNoteModal (dispatch `open-create-note`); convert reuses the task dialog
 * (dispatch `open-create-task` with `fromNoteId`); delete, visibility, pin and
 * reorder come from the shared {@see ManagesNotes} concern.
 */
#[Title('Notes')]
class NoteList extends Component
{
    use ManagesNotes;

    /**
     * Free-text query matched against note titles and bodies.
     */
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Project filter: '' for all, 'none' for projectless notes, or a project id.
     */
    #[Url(as: 'project')]
    public string $projectFilter = '';

    /**
     * The user's own notes for the current search and project filter, ordered
     * pinned-first then by manual position. Empty-title drafts left behind by an
     * abandoned note dialog are hidden.
     *
     * @return EloquentCollection<int, Note>
     */
    #[Computed]
    public function notes(): EloquentCollection
    {
        $notes = Auth::user()->notes()
            ->where('title', '!=', '')
            ->with(['project', 'convertedTask.project']);

        $search = trim($this->search);

        if ($search !== '') {
            $like = $this->likePattern($search);
            $operator = GlobalSearch::likeOperatorFor((new Note)->getConnection()->getDriverName());

            $notes->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhere('body', $operator, $like));
        }

        if ($this->projectFilter === 'none') {
            $notes->whereNull('project_id');
        } elseif ($this->projectFilter !== '') {
            $notes->where('project_id', (int) $this->projectFilter);
        }

        return $notes
            ->orderByDesc('is_pinned')
            ->orderByDesc('position')
            ->get();
    }

    /**
     * The projects the user has notes in, for the filter dropdown.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function filterProjects(): EloquentCollection
    {
        return Project::query()
            ->whereIn('id', Auth::user()->notes()->whereNotNull('project_id')->distinct()->pluck('project_id'))
            ->orderBy('title')
            ->get();
    }

    /**
     * Whether a search or project filter is narrowing the list. Reordering is
     * offered only on the full, unfiltered list, where a note's neighbour in the
     * view matches its true neighbour by position.
     */
    #[Computed]
    public function isFiltering(): bool
    {
        return trim($this->search) !== '' || $this->projectFilter !== '';
    }

    /**
     * Build an escaped LIKE pattern for a case-insensitive "contains" match.
     */
    private function likePattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    protected function forgetNotes(): void
    {
        unset($this->notes, $this->filterProjects);
    }

    public function render(): View
    {
        return view('livewire.notes.note-list');
    }
}
