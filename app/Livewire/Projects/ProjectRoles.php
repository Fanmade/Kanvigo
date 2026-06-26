<?php

namespace App\Livewire\Projects;

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Fanmade\DelegatedPermissions\RoleManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-project role management with constrained delegation. A manager sees only
 * the roles they may act on — the role(s) they hold and everything beneath them,
 * never an ancestor and never the system root. New roles are created under a
 * chosen parent and bounded by that parent's permissions; only roles strictly
 * below the manager (custom, non-base) may be deleted. Restricted to holders of
 * the project `manage-roles` permission.
 */
class ProjectRoles extends Component
{
    use AuthorizesRequests;

    /** The seeded base roles, which may not be deleted here. */
    private const array PROTECTED_ROLES = ['owner', 'admin', 'member', 'viewer'];

    #[Locked]
    public int $projectId;

    public string $name = '';

    public ?int $parentId = null;

    /** @var array<int, int> */
    public array $permissionIds = [];

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->authorize('manage-roles', $project);

        // Default the parent to the manager's own (highest) role.
        $this->parentId = Auth::user()->rolesIn($project)
            ->reject(static fn (Role $role): bool => (bool) $role->is_system)
            ->first()?->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    /**
     * The roles the manager may see and act on, base roles first.
     *
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function roles(): EloquentCollection
    {
        return Auth::user()->visibleRoles($this->project())
            ->sortBy(fn (Role $role): string => sprintf('%d-%s', $this->isProtected($role) ? 0 : 1, $role->name))
            ->values();
    }

    /**
     * Roles the manager may delegate from — the same visible set.
     *
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function assignableParents(): EloquentCollection
    {
        return $this->roles();
    }

    /**
     * The currently chosen parent role, if any.
     */
    #[Computed]
    public function parentRole(): ?Role
    {
        return $this->assignableParents()->firstWhere('id', $this->parentId);
    }

    /**
     * The chosen parent's permissions, grouped for the picker — a child may only
     * be granted a subset of its parent. Empty until a parent is chosen.
     *
     * @return array<string, list<Permission>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        $parent = $this->parentRole();

        if ($parent === null) {
            return [];
        }

        $allowed = app(PermissionResolver::class)->permissionsFor($parent);
        $byName = $this->permissions()->keyBy('name');

        $groups = [];

        foreach (ProjectRoleProvisioner::GROUPS as $group => $names) {
            $perms = [];

            foreach ($names as $name) {
                if ($allowed->contains($name) && $byName->has($name)) {
                    $perms[] = $byName->get($name);
                }
            }

            if ($perms !== []) {
                $groups[$group] = $perms;
            }
        }

        return $groups;
    }

    /**
     * The project permission catalog as Permission models.
     *
     * @return EloquentCollection<int, Permission>
     */
    #[Computed]
    public function permissions(): EloquentCollection
    {
        return Permission::query()
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->get();
    }

    /**
     * The effective permission names each visible role holds, keyed by role id.
     *
     * @return array<int, array<int, string>>
     */
    #[Computed]
    public function permissionsByRole(): array
    {
        $resolver = app(PermissionResolver::class);

        return $this->roles()->mapWithKeys(
            static fn (Role $role): array => [$role->id => $resolver->permissionsFor($role)->sort()->values()->all()],
        )->all();
    }

    /**
     * The ids of roles the manager may delete: strictly below them (visible but
     * not one of their own roles) and not a seeded base role.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function deletableRoleIds(): Collection
    {
        $heldIds = Auth::user()->rolesIn($this->project())->pluck('id');

        return $this->roles()
            ->reject(fn (Role $role): bool => $this->isProtected($role) || $heldIds->contains($role->id))
            ->pluck('id');
    }

    public function createRole(RoleManager $roles): void
    {
        $project = $this->project();
        $this->authorize('manage-roles', $project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'parentId' => ['required', 'integer'],
            'permissionIds' => ['array'],
            'permissionIds.*' => ['integer'],
        ]);

        $parent = $this->assignableParents()->firstWhere('id', $validated['parentId']);

        if ($parent === null) {
            $this->addError('parentId', __('Choose a parent role you manage.'));

            return;
        }

        $exists = Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->id)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            $this->addError('name', __('A role with that name already exists.'));

            return;
        }

        // Bound the chosen permissions to the parent (the picker already filters,
        // this is the safety net so a tampered id can't escalate).
        $allowed = app(PermissionResolver::class)->permissionsFor($parent);
        $names = Permission::query()->whereKey($validated['permissionIds'] ?? [])->pluck('name')
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values()
            ->all();

        $roles->createRole($validated['name'], $parent, $names, $project);

        $this->reset('name', 'permissionIds');
        unset($this->roles, $this->permissionsByRole, $this->deletableRoleIds);

        Flux::toast(variant: 'success', text: __('Role created.'));
    }

    public function deleteRole(RoleManager $roles, int $roleId): void
    {
        $project = $this->project();
        $this->authorize('manage-roles', $project);

        // Only roles strictly below the manager, and never a seeded base role.
        if (! $this->deletableRoleIds()->contains($roleId)) {
            return;
        }

        $role = $this->roles()->firstWhere('id', $roleId);

        if ($role !== null) {
            $roles->deleteRole($role);
            unset($this->roles, $this->permissionsByRole, $this->deletableRoleIds);

            Flux::toast(variant: 'success', text: __('Role deleted.'));
        }
    }

    private function isProtected(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_ROLES, true);
    }

    public function render(): View
    {
        return view('livewire.projects.project-roles');
    }
}
