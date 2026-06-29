<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RelationshipType;
use App\Http\Controllers\Controller;
use App\Models\Dependency;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DependencyController extends Controller
{
    /**
     * Link a typed relationship between the task at {reference} and a related
     * task. The "direction" keyword reads from the task to the related one —
     * "blocked_by", "blocks", "relates", "duplicates", "duplicated_by",
     * "clones", "cloned_by", "causes", "caused_by". Only blocking links affect
     * whether a task is blocked; self-links and blocking cycles are rejected.
     */
    public function store(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'related' => ['required', 'string'],
            'direction' => ['required', Rule::in(RelationshipType::keywords())],
        ]);

        [$item, $related] = $this->resolvePair($reference, $validated['related']);

        [$type, $asSubject] = RelationshipType::fromKeyword($validated['direction']);

        try {
            $item->addRelationship($related, $type, $asSubject);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'related' => __('That would make an item depend on itself or create a cycle.'),
            ]);
        }

        $item->recordDependencyChange(true, $validated['direction'], $related->reference);

        return response()->json(['data' => $this->payload($item)], 201);
    }

    /**
     * Remove the dependency between two tasks, in whichever direction it exists.
     */
    public function destroy(string $reference, string $related): JsonResponse
    {
        [$item, $relatedTask] = $this->resolvePair($reference, $related);

        abort_if($item->removeRelationshipWith($relatedTask) === null, 404);

        return response()->json(['data' => $this->payload($item)]);
    }

    /**
     * Resolve the changed task (which the user must be able to update) and the
     * related task (which they must at least be able to view). Either being
     * missing or inaccessible is a 404 — only tasks take part in a dependency.
     *
     * @return array{0: Task, 1: Task}
     */
    private function resolvePair(string $reference, string $relatedReference): array
    {
        $item = ReferenceResolver::task($reference);
        abort_if(! $item instanceof Task || Auth::user()->cannot('update', $item), 404);

        $related = ReferenceResolver::task($relatedReference);
        abort_if(! $related instanceof Task || Auth::user()->cannot('view', $related), 404);

        return [$item, $related];
    }

    /**
     * Build the dependency payload for a task — the references of what blocks it,
     * what it blocks, and whether it is currently blocked — eager-loading the
     * linked items in one pass to keep reference resolution N+1-free.
     *
     * @return array<string, string|array<int, string>|bool>
     */
    private function payload(Task $item): array
    {
        $item->loadMissing([
            'dependencyLinks.blocker' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                Task::class => ['project'],
            ]),
            'dependentLinks.dependent' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                Task::class => ['project'],
            ]),
        ]);

        return [
            'reference' => $item->reference,
            ...$item->relationshipReferences(),
            'is_blocked' => $item->isBlocked(),
        ];
    }
}
