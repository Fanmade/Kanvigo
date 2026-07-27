<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Referenceable;
use App\Enums\ReferenceOrigin;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocDetailResource;
use App\Http\Resources\TaskDetailResource;
use App\Models\Doc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Cross-references between items: a plain, directed link from a task or doc to
 * another task or doc. Unlike a dependency a reference never blocks anything and
 * may be circular. Links made here are curated — they survive edits to the items'
 * text, unlike the ones written inline as "#PROJ-42" in a body, which follow the
 * text and are re-derived on every save.
 *
 * The `/tasks/{reference}/references` and `/docs/{reference}/references` paths are
 * the same endpoint; each resolves whichever item the reference names.
 */
class ReferenceController extends Controller
{
    use ResolvesApiReferences;

    /**
     * Link the item at {reference} to another task or doc.
     */
    public function store(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'related' => ['required', 'string'],
        ]);

        $item = $this->resolveReferenceableOr404($reference, 'update');
        $related = $this->resolveReferenceableOr404($validated['related']);

        try {
            $item->addReference($related, ReferenceOrigin::Manual);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'related' => __('An item cannot reference itself.'),
            ]);
        }

        return $this->detailResource($item)->response()->setStatusCode(201);
    }

    /**
     * Unlink the reference from the item at {reference} to {related}. The link the
     * other way round, if any, is left in place.
     */
    public function destroy(string $reference, string $related): JsonResource
    {
        $item = $this->resolveReferenceableOr404($reference, 'update');
        $relatedItem = $this->resolveReferenceableOr404($related);

        abort_unless(
            $item->references()->contains(static fn (Model $linked): bool => $linked->is($relatedItem)),
            404,
        );

        $item->removeReference($relatedItem);

        return $this->detailResource($item);
    }

    /**
     * The detail resource for whichever kind of item was changed, so the response
     * matches what GET on that item returns.
     */
    private function detailResource(Model&Referenceable $item): JsonResource
    {
        $item->unsetRelation('outgoingReferences')->unsetRelation('incomingReferences');

        return $item instanceof Doc
            ? new DocDetailResource($item->loadMissing(['project', 'parent', 'tags', 'children', 'attachments']))
            : new TaskDetailResource($item->loadMissing(['project', 'parent', 'tags', 'taskType', 'children', 'assignees', 'attachments', 'dependencyLinks.blocker']));
    }
}
