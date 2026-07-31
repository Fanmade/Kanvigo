<?php

namespace App\Queries;

use App\Contracts\UsesVariables;
use App\Models\Variable;
use App\Support\VariableSyntax;
use Illuminate\Database\Eloquent\Collection;

/**
 * The project variables an item's content actually names — the "variables"
 * sidecar the MCP tools and the API detail resources return alongside the raw
 * body.
 *
 * Machine reads deliberately get the content exactly as stored, `[name]` intact:
 * substituting on read would make a read-edit-write round trip destructive, since
 * the client would write back baked values and silently delete every usage. The
 * sidecar is how the values travel instead.
 *
 * Names are re-parsed from the current content rather than read from the usage
 * index, which is allowed to lag; a read must never report a usage the content no
 * longer has.
 */
class VariablesUsedIn
{
    /**
     * The defined variables the item's content uses, in name order. A name the
     * project does not define has no variable and is simply absent — the content
     * still shows it, unresolved.
     *
     * @return Collection<int, Variable>
     */
    public function handle(UsesVariables $item): Collection
    {
        $projectId = $item->variableNamespaceProjectId();
        $names = VariableSyntax::namesIn($item->variableContent());

        if ($projectId === null || $names === []) {
            return new Collection;
        }

        return Variable::query()
            ->where('project_id', $projectId)
            ->whereIn('name', $names)
            ->orderBy('name')
            ->get();
    }
}
