<?php

namespace App\Jobs;

use App\Actions\RewriteVariableUsages as RewriteUsages;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Rewrites `[old]` as `[new]` in every item of a project that uses the renamed
 * variable (KAN-461), off the request that renamed it.
 *
 * This is the one operation that changes stored content, and it is not an
 * exception to "a value change rewrites nothing": a rename changes what the
 * pointer is *called*, while the document keeps saying the same thing. Rewriting
 * is what keeps it saying it, so the edit is honest — it moves `updated_at` and
 * is attributed to the user who renamed.
 *
 * Usages written in the moments before the rename may be missed; they show up
 * afterwards as unknown names on the variables page, and rerunning the rename
 * picks them up.
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

    public function handle(RewriteUsages $rewrite): void
    {
        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        $previous = Auth::user();

        // Attribute the content edits to whoever renamed, not to nobody.
        if ($actor instanceof User) {
            Auth::setUser($actor);
        }

        try {
            $rewrite->handle($this->projectId, $this->from, $this->to);
        } finally {
            $previous instanceof User ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }
}
