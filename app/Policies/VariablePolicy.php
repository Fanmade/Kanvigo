<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Variable;

/**
 * Variable authorization resolves each ability to a project-scoped permission,
 * bridging a variable subject to its project (mirroring {@see DocPolicy}).
 *
 * Reading is deliberately wider than writing: a variable's value shows up in
 * every doc, description and comment that names it, so anyone who can see the
 * project can see what its variables stand for. Creating, renaming, setting a
 * value and deleting are all the single `manage-variables` permission — there is
 * no create/edit/delete split, and no permission for *using* a variable, because
 * writing [hero] in prose is just typing.
 *
 * Ability names must stay distinct from the catalog permission names, or
 * Gate::before would auto-grant the ability and bypass the method. See the
 * naming contract on {@see ProjectPolicy}; AuthorizationContractTest enforces it.
 */
class VariablePolicy
{
    /**
     * Seeing a variable and what it currently stands for.
     */
    public function view(User $user, Variable $variable): bool
    {
        return $user->hasScopedPermission('view-project', $variable->project);
    }

    /**
     * Renaming a variable, changing its value or its description. Creating one
     * is gated by `manage-variables` on the project directly (the package's
     * Gate::before grants it), so there is no create() method here.
     */
    public function update(User $user, Variable $variable): bool
    {
        return $user->hasScopedPermission('manage-variables', $variable->project);
    }

    public function delete(User $user, Variable $variable): bool
    {
        return $user->hasScopedPermission('manage-variables', $variable->project);
    }
}
