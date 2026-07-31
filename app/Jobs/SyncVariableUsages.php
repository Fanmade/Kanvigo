<?php

namespace App\Jobs;

use App\Contracts\UsesVariables;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-reads one item's content and reconciles its rows in the variable usage
 * index (KAN-460).
 *
 * Queued on purpose: the index is derived state that nothing on the render path
 * reads, so keeping it out of the save request costs nothing a reader can see.
 * If the model is gone by the time the job runs, there is nothing to index — the
 * delete already removed its rows.
 */
class SyncVariableUsages implements ShouldQueue
{
    use Queueable;

    /**
     * Drop the job rather than fail it when the item has since been deleted.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public UsesVariables&Model $item) {}

    public function handle(): void
    {
        $this->item->syncVariableUsages();
    }
}
