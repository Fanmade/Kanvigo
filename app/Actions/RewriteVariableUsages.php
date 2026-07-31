<?php

namespace App\Actions;

use App\Contracts\UsesVariables;
use App\Models\VariableUsage;
use App\Support\VariableSyntax;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Rewrites `[old]` as `[new]` in every item of a project that uses a renamed
 * variable, returning how many items were actually changed.
 *
 * Shared by the queued job behind the UI rename and the MCP update-variable tool,
 * which runs it inline because there is nobody to confirm with and the agent has
 * to be told what happened.
 *
 * The usage index only says *where to look*: each item's current content is
 * re-read and re-parsed before anything is replaced, so a stale index row can
 * cost a wasted load but never a wrong write.
 */
class RewriteVariableUsages
{
    /**
     * @return int the number of items whose content was rewritten
     */
    public function handle(int $projectId, string $from, string $to): int
    {
        $rewritten = 0;

        VariableUsage::query()
            ->where('project_id', $projectId)
            ->where('name', $from)
            ->with('usable')
            ->chunkById(100, function (Collection $usages) use ($from, $to, &$rewritten): void {
                /** @var Collection<int, VariableUsage> $usages */
                foreach ($usages as $usage) {
                    $rewritten += $this->rewrite($usage->usable, $from, $to) ? 1 : 0;
                }
            });

        return $rewritten;
    }

    /**
     * Re-read one item's current content and rewrite its usages, if it still has
     * any. A missing item (deleted since the index row was written) is skipped.
     */
    private function rewrite(?Model $item, string $from, string $to): bool
    {
        if (! $item instanceof UsesVariables) {
            return false;
        }

        $rewritten = VariableSyntax::rename($item->variableContent(), $from, $to);

        if ($rewritten === null) {
            return false;
        }

        $item->setAttribute($item->variableContentColumn(), $rewritten);
        $item->save();

        return true;
    }
}
