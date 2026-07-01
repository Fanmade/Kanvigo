<?php

namespace App\Livewire\Notes;

use App\Actions\CreateNote;
use App\Actions\UpdateNote;
use App\Concerns\HandlesAttachments;
use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The globally-mounted create/edit dialog for notes, opened from anywhere by
 * dispatching {@see open()}'s `open-create-note` event (optionally with a note
 * id to edit). Mirrors {@see CreateTaskModal}; the body uses
 * the shared rich-text editor with inline-image support via HandlesAttachments.
 *
 * @property-read Collection<int, Project> $projects
 */
class CreateNoteModal extends Component
{
    use HandlesAttachments;

    public bool $show = false;

    /**
     * The note being edited, or a draft lazily created to hold inline images
     * pasted while composing a brand-new note.
     */
    public ?int $noteId = null;

    /**
     * Whether the current note was created by this dialog and not yet saved — a
     * throwaway draft discarded if the dialog is dismissed.
     */
    public bool $draft = false;

    public string $title = '';

    public string $body = '';

    public ?int $projectId = null;

    public bool $isPublic = false;

    /**
     * Open the dialog for a new note, or to edit an existing one.
     */
    #[On('open-create-note')]
    public function open(?int $noteId = null): void
    {
        $this->resetForm();

        if ($noteId !== null) {
            $note = Note::findOrFail($noteId);
            $this->authorize('update', $note);

            $this->noteId = $note->id;
            $this->title = $note->title;
            $this->body = (string) $note->body;
            $this->projectId = $note->project_id;
            $this->isPublic = $note->is_public;
        }

        $this->show = true;
    }

    /**
     * The note inline images attach to. A brand-new note is created lazily here
     * (the first time an image is pasted) so the editor has a target before the
     * note is saved; it is discarded with the dialog if never committed.
     */
    protected function attachable(): Note
    {
        if ($this->noteId !== null) {
            return Note::findOrFail($this->noteId);
        }

        $note = $this->user()->notes()->create([
            'title' => $this->title,
            'is_public' => false,
        ]);

        $this->noteId = $note->id;
        $this->draft = true;

        return $note;
    }

    /**
     * The projects the user may attach a note to.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->user()->projects()->orderBy('title')->get();
    }

    /**
     * Clearing the project makes the note private again (the public invariant).
     */
    public function updatedProjectId(): void
    {
        if ($this->projectId === null) {
            $this->isPublic = false;
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'projectId' => ['nullable', 'integer'],
            'isPublic' => ['boolean'],
        ]);

        $project = $this->resolveProject($validated['projectId'] ?? null);
        $body = $validated['body'] ?: null;

        if ($this->noteId === null) {
            app(CreateNote::class)->handle($this->user(), $validated['title'], $body, $project, $this->isPublic);
        } else {
            $note = $this->user()->notes()->findOrFail($this->noteId);
            app(UpdateNote::class)->handle($note, $validated['title'], $body, $project, $this->isPublic);
        }

        // Committed: keep the (formerly draft) note.
        $this->draft = false;

        $this->dispatch('note-saved');
        $this->close();

        Flux::toast(text: __('Note saved.'), variant: 'success');
    }

    /**
     * Dismiss the dialog, discarding a lazily-created draft that was never saved.
     */
    public function close(): void
    {
        if ($this->draft && $this->noteId !== null) {
            $note = Note::find($this->noteId);

            // Remove its inline attachments (and files) before hard-deleting the draft.
            $note?->attachments->each->delete();
            $note?->forceDelete();
        }

        $this->resetForm();
        $this->show = false;
    }

    /**
     * Resolve the chosen project, scoped to the user's own memberships.
     */
    protected function resolveProject(?int $projectId): ?Project
    {
        if ($projectId === null) {
            return null;
        }

        $project = $this->projects->firstWhere('id', $projectId);

        if ($project === null) {
            $this->addError('projectId', __('The selected project is not valid.'));
            abort(422);
        }

        return $project;
    }

    protected function resetForm(): void
    {
        $this->reset('noteId', 'draft', 'title', 'body', 'projectId', 'isPublic', 'inlineImage', 'newFiles');
        $this->resetValidation();
        unset($this->projects);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
