<?php

namespace App\Jobs;

use App\Contracts\UsesVariables;
use App\Models\User;
use App\Models\VariableUsage;
use App\Support\VariableSyntax;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Rewrites `[old]` as `[new]` in every item of a project that uses the renamed
 * variable (KAN-461).
 *
 * This is the one operation that changes stored content, and it is not an
 * exception to "a value change rewrites nothing": a rename changes what the
 * pointer is *called*, while the document keeps saying the same thing. Rewriting
 * is what keeps it saying it, so the edit is honest — it moves `updated_at` and
 * is attributed to the user who renamed.
 *
 * The usage index only says *where to look*. Each item's current content is
 * re-read and re-parsed before anything is replaced, so a stale index row can
 * cost a wasted load but never a wrong write. Usages written in the moments
 * before the rename may be missed; they show up afterwards as unknown names on
 * the variables page, and rerunning the rename picks them up.
 */
class RewriteVariableUsages implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $from,
        public string $to,
        public ?int $actorId = null,
    ) {}

    public function handle(): void
    {
        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        $previous = Auth::user();

        // Attribute the content edits to whoever renamed, not to nobody.
        if ($actor instanceof User) {
            Auth::setUser($actor);
        }

        try {
            $this->rewriteAll();
        } finally {
            $previous instanceof User ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /**
     * Rewrite each item the index points at, in chunks.
     */
    private function rewriteAll(): void
    {
        VariableUsage::query()
            ->where('project_id', $this->projectId)
            ->where('name', $this->from)
            ->with('usable')
            ->chunkById(100, function (Collection $usages): void {
                /** @var Collection<int, VariableUsage> $usages */
                foreach ($usages as $usage) {
                    $this->rewrite($usage->usable);
                }
            });
    }

    /**
     * Re-read one item's current content and rewrite its usages, if it still has
     * any. A missing item (deleted since the index row was written) is skipped.
     */
    private function rewrite(?Model $item): void
    {
        if (! $item instanceof UsesVariables) {
            return;
        }

        $rewritten = VariableSyntax::rename($item->variableContent(), $this->from, $this->to);

        if ($rewritten === null) {
            return;
        }

        $item->setAttribute($item->variableContentColumn(), $rewritten);
        $item->save();
    }
}
