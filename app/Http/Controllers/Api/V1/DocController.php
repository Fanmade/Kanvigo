<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocDetailResource;
use App\Http\Resources\DocResource;
use App\Models\Doc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DocController extends Controller
{
    use ResolvesApiReferences;

    /**
     * The relations a doc resource serializes.
     *
     * @var list<string>
     */
    private const RESOURCE_RELATIONS = ['project', 'parent', 'tags', 'children', 'attachments'];

    /**
     * List a project's reference docs, paginated in tree order, optionally
     * restricted to the docs nested directly under a parent doc. Drafts are
     * included only for members who may edit the project's docs.
     */
    public function index(Request $request, string $short_name): AnonymousResourceCollection
    {
        $project = $this->resolveProjectOr404($short_name);

        $validated = $request->validate([
            'parent' => ['nullable', 'string'],
        ]);

        $parent = isset($validated['parent'])
            ? $this->resolveDocOr404($validated['parent'])
            : null;

        abort_if($parent !== null && $parent->project_id !== $project->id, 404);

        $docs = $project->docs()
            ->with(['project', 'parent', 'tags'])
            ->unless(Auth::user()->can('edit-doc', $project), static fn (Builder $query): Builder => $query->where('is_public', true))
            ->when($parent !== null, static fn (Builder $query): Builder => $query->where('parent_id', $parent->id))
            ->orderBy('position')
            ->orderBy('doc_number')
            ->paginate();

        return DocResource::collection($docs);
    }

    /**
     * Show a single doc by reference (e.g. "PROJ-D3"), with its body, nested
     * docs, cross-references and attachments.
     */
    public function show(string $reference): DocDetailResource
    {
        $doc = $this->resolveDocOr404($reference);

        return new DocDetailResource($doc->loadMissing(self::RESOURCE_RELATIONS));
    }

    /**
     * Create a doc in a project. It is a draft unless `is_public` is true, and
     * top-level unless nested under a `parent` doc. Requires write access and the
     * create-doc permission.
     */
    public function store(Request $request, string $short_name): JsonResponse
    {
        $project = $this->resolveProjectOr404($short_name);
        abort_if(Auth::user()->cannot('create-doc', $project), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'parent' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $parent = null;

        if (isset($validated['parent'])) {
            $parent = $this->resolveDocOr404($validated['parent']);

            abort_if($parent->project_id !== $project->id, 404);
        }

        try {
            $doc = $project->docs()->create([
                'title' => $validated['title'],
                'body' => $validated['body'] ?? null,
                'parent_id' => $parent?->getKey(),
                'is_public' => (bool) ($validated['is_public'] ?? false),
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'parent' => __('The doc cannot be nested there: it would exceed the maximum nesting depth.'),
            ]);
        }

        return DocDetailResource::make($doc->load(self::RESOURCE_RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Update a doc's title, body, parent or published flag. PATCH is partial:
     * only the fields actually sent are changed. Pass `parent: null` to move the
     * doc to the top level. Requires write access and the edit-doc permission.
     */
    public function update(Request $request, string $reference): DocDetailResource
    {
        $doc = $this->resolveDocOr404($reference);
        abort_if(Auth::user()->cannot('update', $doc), 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'parent' => ['sometimes', 'nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $changes = [];

        if ($request->has('title')) {
            $changes['title'] = $validated['title'];
        }

        if ($request->has('body')) {
            $changes['body'] = $validated['body'] ?? null;
        }

        if ($request->has('is_public')) {
            $changes['is_public'] = (bool) ($validated['is_public'] ?? false);
        }

        if ($request->has('parent')) {
            $changes['parent_id'] = $this->resolveNewParent($validated['parent'] ?? null, $doc);
        }

        // The model rejects a parent that closes a cycle or nests too deep.
        try {
            $doc->update($changes);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'parent' => __('The doc cannot be nested there: a doc cannot sit under itself or its own nested docs, and the tree has a maximum depth.'),
            ]);
        }

        return new DocDetailResource($doc->load(self::RESOURCE_RELATIONS));
    }

    /**
     * Delete a doc. The delete is soft, and the docs nested under it are kept —
     * they read as top-level while their parent is gone, and nest again if it is
     * restored. Requires write access and the delete-doc permission.
     */
    public function destroy(string $reference): JsonResponse
    {
        $doc = $this->resolveDocOr404($reference);
        abort_if(Auth::user()->cannot('delete', $doc), 403);

        $doc->delete();

        return response()->json(status: 204);
    }

    /**
     * Resolve the new parent id for a re-parent: null moves the doc to the top
     * level, anything else must be a doc in the same project.
     */
    private function resolveNewParent(?string $reference, Doc $doc): ?int
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        $parent = $this->resolveDocOr404($reference);

        abort_if($parent->project_id !== $doc->project_id, 404);

        return $parent->getKey();
    }
}
