<?php

namespace App\Policies;

use App\Models\Doc;
use App\Models\User;

/**
 * Doc authorization resolves each ability to a project-scoped permission
 * (mirroring {@see TaskPolicy}), bridging a doc subject to its project. Creating
 * a doc is gated by the `create-doc` permission directly on the project (the
 * package's Gate::before grants it), so there is no create() method here.
 *
 * Visibility has two tiers, mirroring public notes but project-role gated: a
 * draft (is_public = false) is visible only to editors, while a published doc
 * (is_public = true) is visible to anyone who can view the project.
 *
 * Ability names must stay distinct from the catalog permission names, or
 * Gate::before would auto-grant the ability and bypass the method. See the
 * naming contract on {@see ProjectPolicy}; AuthorizationContractTest enforces it.
 */
class DocPolicy
{
    /**
     * Editors see every doc (including drafts); everyone else sees a doc only
     * once it is published to the project.
     */
    public function view(User $user, Doc $doc): bool
    {
        if ($user->hasScopedPermission('edit-doc', $doc->project)) {
            return true;
        }

        return $doc->is_public && $user->hasScopedPermission('view-project', $doc->project);
    }

    /**
     * Editing a doc's title, body, visibility or parent.
     */
    public function update(User $user, Doc $doc): bool
    {
        return $user->hasScopedPermission('edit-doc', $doc->project);
    }

    public function delete(User $user, Doc $doc): bool
    {
        return $user->hasScopedPermission('delete-doc', $doc->project);
    }

    /**
     * Applying or removing tags on the doc — part of editing it.
     */
    public function tag(User $user, Doc $doc): bool
    {
        return $user->hasScopedPermission('edit-doc', $doc->project);
    }
}
