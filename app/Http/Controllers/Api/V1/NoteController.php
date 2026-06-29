<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConvertNote;
use App\Actions\CreateNote;
use App\Actions\CreateTask;
use App\Actions\UpdateNote;
use App\Http\Controllers\Controller;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Models\Project;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class NoteController extends Controller
{
    /**
     * The relations a note resource serializes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['project', 'convertedTask.project'];

    /**
     * List the authenticated user's own notes, most recently updated first.
     */
    public function index(): AnonymousResourceCollection
    {
        $notes = Auth::user()->notes()
            ->with(self::RELATIONS)
            ->latest('updated_at')
            ->paginate();

        return NoteResource::collection($notes);
    }

    /**
     * Show a single note by id. Visible to its owner, or to a project's members
     * when the note is public and attached to that project.
     */
    public function show(int $note): NoteResource
    {
        $model = Note::with(self::RELATIONS)->find($note);

        abort_if($model === null || Auth::user()->cannot('view', $model), 404);

        return new NoteResource($model);
    }

    /**
     * Capture a new note for the caller. Optionally attach it to a project and
     * make it public to that project.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateNote($request);

        $note = app(CreateNote::class)->handle(
            Auth::user(),
            $validated['title'],
            $validated['body'] ?? null,
            $this->resolveProject($validated['project'] ?? null),
            (bool) ($validated['is_public'] ?? false),
        );

        return NoteResource::make($note->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Update one of the caller's own notes.
     */
    public function update(Request $request, int $note): NoteResource
    {
        $model = $this->ownNote($note, 'update');

        $validated = $this->validateNote($request);

        app(UpdateNote::class)->handle(
            $model,
            $validated['title'],
            $validated['body'] ?? null,
            $this->resolveProject($validated['project'] ?? null),
            (bool) ($validated['is_public'] ?? false),
        );

        return new NoteResource($model->load(self::RELATIONS));
    }

    /**
     * Delete one of the caller's own notes.
     */
    public function destroy(int $note): JsonResponse
    {
        $this->ownNote($note, 'delete')->delete();

        return response()->json(status: 204);
    }

    /**
     * Convert one of the caller's notes into a task in a project (optionally
     * nested under a parent). The note is kept and linked to the new task.
     */
    public function convert(Request $request, int $note): NoteResource
    {
        $model = $this->ownNote($note, 'update');

        $validated = $request->validate([
            'project' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
        ]);

        $project = ReferenceResolver::project($validated['project']);

        if ($project === null || Auth::user()->cannot('create-task', $project)) {
            throw ValidationException::withMessages(['project' => __('The selected project is not valid.')]);
        }

        $parent = null;

        if (isset($validated['parent'])) {
            $parent = ReferenceResolver::task($validated['parent']);

            if ($parent === null || $parent->project_id !== $project->id || Auth::user()->cannot('view', $parent)) {
                throw ValidationException::withMessages(['parent' => __('The selected parent task is not valid.')]);
            }
        }

        try {
            $task = app(CreateTask::class)->handle($project, $model->title, $model->body, null, null, null, $parent);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'parent' => __('The task cannot be nested there: it would exceed the maximum nesting depth.'),
            ]);
        }

        app(ConvertNote::class)->handle($model, $task);

        return new NoteResource($model->load(self::RELATIONS));
    }

    /**
     * Validate a note's writable fields.
     *
     * @return array<string, mixed>
     */
    private function validateNote(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'is_public' => ['boolean'],
        ]);
    }

    /**
     * Resolve a project short name the caller may attach a note to, or null when
     * none is given. An unknown or inaccessible project is a validation error.
     */
    private function resolveProject(?string $shortName): ?Project
    {
        if ($shortName === null || trim($shortName) === '') {
            return null;
        }

        $project = ReferenceResolver::project($shortName);

        if ($project === null || Auth::user()->cannot('view', $project)) {
            throw ValidationException::withMessages(['project' => __('The selected project is not valid.')]);
        }

        return $project;
    }

    /**
     * Resolve one of the caller's own notes for a write action: 404 when missing,
     * 403 when it isn't theirs.
     */
    private function ownNote(int $id, string $ability): Note
    {
        $note = Note::find($id);

        abort_if($note === null, 404);
        abort_if(Auth::user()->cannot($ability, $note), 403);

        return $note;
    }
}
