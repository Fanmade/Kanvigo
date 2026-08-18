<?php

namespace App\Livewire\Projects;

use App\Authorization\PermissionCatalog;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\DelegatedPermissions;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The per-project roles settings page: a master–detail view over the roles the
 * current manager may act on. The left pane lists the visible role tree by name
 * and hierarchy only; the right pane describes the selected role — its parent,
 * effective permissions grouped per {@see ProjectRoleProvisioner::GROUPS}, and
 * the members holding it. Read-only for now; the editing affordances land in a
 * follow-up. Restricted to holders of the project `manage-roles` permission.
 *
 * Visibility is the package's delegation set ({@see User::visibleRoles()}): the
 * roles the manager holds and everything beneath them, never an ancestor and
 * never the system root.
 *
 * @property-read Project $project
 */
class ProjectRoles extends Component
{
    use AuthorizesRequests;

    /** The seeded base roles, marked as such and not manager-created. */
    private const array BASE_ROLES = ['owner', 'admin', 'member', 'viewer'];

    #[Locked]
    public string $shortName;

    /** The role open in the detail pane, deep-linked as `?role=`. */
    #[Url(as: 'role')]
    public ?int $selectedRoleId = null;

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('manage-roles', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('manage-roles', $project);

        return $project;
    }

    /**
     * The roles the manager may see, base roles first.
     *
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function roles(): EloquentCollection
    {
        return Auth::user()->visibleRoles($this->project)
            ->sortBy(fn (Role $role): string => sprintf('%d-%s', $this->isBaseRole($role) ? 0 : 1, $role->name))
            ->values();
    }

    /**
     * The visible roles flattened into hierarchy order: each entry is a role with
     * its depth (number of visible ancestors), parent before children, so the
     * tree can indent a role under the parent it was delegated from.
     *
     * @return list<array{role: Role, depth: int}>
     */
    #[Computed]
    public function roleTree(): array
    {
        $roles = $this->roles();
        $byId = $roles->keyBy('id');
        $childrenByParent = $roles->groupBy(static fn (Role $role): int => $role->parent_id ?? 0);

        $ordered = [];

        $visit = static function (Role $role, int $depth) use (&$visit, &$ordered, $childrenByParent): void {
            $ordered[] = ['role' => $role, 'depth' => $depth];

            foreach ($childrenByParent->get($role->id, collect()) as $child) {
                $visit($child, $depth + 1);
            }
        };

        // Start from each visible role whose parent is not itself visible (a root
        // of the visible subtree), then walk down.
        foreach ($roles as $role) {
            if ($role->parent_id === null || ! $byId->has($role->parent_id)) {
                $visit($role, 0);
            }
        }

        return $ordered;
    }

    /**
     * How many members hold each visible role, keyed by role id, in one query —
     * the tree badges must not cost a query per row.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function memberCounts(): array
    {
        $counts = DB::table(DelegatedPermissions::table('role_assignments'))
            ->whereIn('role_id', $this->roles()->modelKeys())
            ->where('authorizable_type', (new User)->getMorphClass())
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as members')
            ->pluck('members', 'role_id');

        return $counts->mapWithKeys(static fn (mixed $members, mixed $roleId): array => [(int) $roleId => (int) $members])->all();
    }

    /**
     * The role shown in the detail pane: the selected one while it is still
     * visible, otherwise the first role of the tree.
     */
    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->roles()->firstWhere('id', $this->selectedRoleId)
            ?? ($this->roleTree()[0]['role'] ?? null);
    }

    /**
     * The selected role's parent, when it is one the manager may see (the parent
     * of a top-level visible role is an ancestor, and stays hidden).
     */
    #[Computed]
    public function selectedRoleParent(): ?Role
    {
        $role = $this->selectedRole();

        return $role === null ? null : $this->roles()->firstWhere('id', $role->parent_id);
    }

    /**
     * The selected role's effective permissions, grouped and labelled for the
     * detail pane. Groups the role holds nothing from are omitted.
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function selectedRolePermissionGroups(): array
    {
        $role = $this->selectedRole();

        if ($role === null) {
            return [];
        }

        $held = app(PermissionResolver::class)->permissionsFor($role);

        $groups = [];

        foreach (ProjectRoleProvisioner::GROUPS as $group => $names) {
            $labels = [];

            foreach ($names as $name) {
                if ($held->contains($name)) {
                    $labels[] = PermissionCatalog::pickerLabel($name);
                }
            }

            if ($labels !== []) {
                $groups[$group] = $labels;
            }
        }

        return $groups;
    }

    /**
     * The members holding the selected role, by name.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function selectedRoleMembers(): EloquentCollection
    {
        $role = $this->selectedRole();

        if ($role === null) {
            return new EloquentCollection;
        }

        return User::query()
            ->whereHas('roles', static fn ($query) => $query->whereKey($role->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Open a visible role in the detail pane (ignoring anything else, so a
     * tampered id cannot reveal a role outside the delegation set).
     */
    public function selectRole(int $roleId): void
    {
        if ($this->roles()->contains('id', $roleId)) {
            $this->selectedRoleId = $roleId;
        }
    }

    /**
     * Whether the role is one of the seeded base roles rather than a custom one
     * a manager created.
     */
    public function isBaseRole(Role $role): bool
    {
        return in_array($role->name, self::BASE_ROLES, true);
    }

    public function render(): View
    {
        return view('livewire.projects.project-roles');
    }
}
