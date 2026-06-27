<?php

namespace App\Livewire\Projects;

use App\Authorization\PermissionCatalog;
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

    /** The custom role currently open for in-place editing, if any. */
    public ?int $editingRoleId = null;

    /** @var array<int, int> */
    public array $editPermissionIds = [];

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
        return $this->groupsBoundedBy($this->parentRole());
    }

    /**
     * The catalog permissions a child of the given parent may hold, grouped for
     * the picker (a child is bounded by its parent). Empty for a null parent.
     *
     * @return array<string, list<Permission>>
     */
    private function groupsBoundedBy(?Role $parent): array
    {
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
     * The custom role currently open for editing, if it is still within the
     * manager's editable set.
     */
    #[Computed]
    public function editingRole(): ?Role
    {
        if ($this->editingRoleId === null || ! $this->editableRoleIds()->contains($this->editingRoleId)) {
            return null;
        }

        return $this->roles()->firstWhere('id', $this->editingRoleId);
    }

    /**
     * The permission picker for the role being edited, bounded by that role's
     * parent.
     *
     * @return array<string, list<Permission>>
     */
    #[Computed]
    public function editPermissionGroups(): array
    {
        return $this->groupsBoundedBy($this->editingRole()?->parent);
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
     * The human-readable, translated label for a permission name, used wherever
     * a permission is shown in the picker. Defers to {@see PermissionCatalog}.
     */
    public function permissionLabel(string $name): string
    {
        return PermissionCatalog::label($name);
    }

    /**
     * The short label for a permission in the role picker, where the group
     * heading already names the subject. Defers to {@see PermissionCatalog}.
     */
    public function permissionPickerLabel(string $name): string
    {
        return PermissionCatalog::pickerLabel($name);
    }

    /**
     * The optional translated description for a permission name, surfaced behind
     * a hint icon in the picker. Null when the permission has no description.
     */
    public function permissionDescription(string $name): ?string
    {
        return PermissionCatalog::description($name);
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

    /**
     * The ids of roles the manager may edit in place — the same custom,
     * strictly-below set as {@see deletableRoleIds()}. Base roles stay
     * code-owned and the manager's own roles are off limits.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function editableRoleIds(): Collection
    {
        return $this->deletableRoleIds();
    }

    /**
     * The available one-click role template names.
     *
     * @return list<string>
     */
    #[Computed]
    public function templates(): array
    {
        return array_keys(ProjectRoleProvisioner::TEMPLATES);
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
        $this->forgetRoleCaches();

        Flux::toast(variant: 'success', text: __('Role created.'));
    }

    /**
     * Instantiate a built-in role template under the chosen parent, granting
     * only the template permissions the parent actually holds (so a template can
     * never escalate past its delegation bound).
     */
    public function applyTemplate(RoleManager $roles, PermissionResolver $resolver, string $template): void
    {
        $project = $this->project();
        $this->authorize('manage-roles', $project);

        $names = ProjectRoleProvisioner::TEMPLATES[$template] ?? null;

        if ($names === null) {
            return;
        }

        $parent = $this->parentRole();

        if ($parent === null) {
            $this->addError('parentId', __('Choose a parent role you manage.'));

            return;
        }

        $exists = Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->id)
            ->where('name', $template)
            ->exists();

        if ($exists) {
            Flux::toast(variant: 'warning', text: __('A role with that name already exists.'));

            return;
        }

        $allowed = $resolver->permissionsFor($parent);
        $granted = array_values(array_filter($names, static fn (string $name): bool => $allowed->contains($name)));

        $roles->createRole($template, $parent, $granted, $project);

        $this->forgetRoleCaches();

        Flux::toast(variant: 'success', text: __('Role created.'));
    }

    /**
     * Open a custom role for in-place editing, seeding the picker with its
     * current catalog permissions.
     */
    public function startEdit(int $roleId): void
    {
        $this->authorize('manage-roles', $this->project());

        if (! $this->editableRoleIds()->contains($roleId)) {
            return;
        }

        $role = $this->roles()->firstWhere('id', $roleId);

        if ($role === null) {
            return;
        }

        $held = app(PermissionResolver::class)->permissionsFor($role);

        $this->editingRoleId = $roleId;
        $this->editPermissionIds = $this->permissions()
            ->filter(static fn (Permission $permission): bool => $held->contains($permission->name))
            ->pluck('id')
            ->all();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingRoleId', 'editPermissionIds');
    }

    /**
     * Apply the edited permission set to a custom role via grant()/revoke().
     * Additions are bounded by the role's parent; revokes cascade to descendants.
     */
    public function saveRole(PermissionResolver $resolver): void
    {
        $this->authorize('manage-roles', $this->project());

        $roleId = $this->editingRoleId;

        if ($roleId === null || ! $this->editableRoleIds()->contains($roleId)) {
            return;
        }

        $role = $this->roles()->firstWhere('id', $roleId);
        $parent = $role?->parent;

        if ($role === null || $parent === null) {
            return;
        }

        $allowed = $resolver->permissionsFor($parent);

        $desired = $this->permissions()
            ->whereIn('id', $this->editPermissionIds)
            ->pluck('name')
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values();

        $current = $resolver->permissionsFor($role)
            ->filter(static fn (string $name): bool => in_array($name, ProjectRoleProvisioner::CATALOG, true))
            ->values();

        foreach ($desired->diff($current) as $name) {
            $resolver->grant($role, $name);
        }

        foreach ($current->diff($desired) as $name) {
            $resolver->revoke($role, $name);
        }

        $this->cancelEdit();
        $this->forgetRoleCaches();

        Flux::toast(variant: 'success', text: __('Role updated.'));
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
            if ($this->editingRoleId === $roleId) {
                $this->cancelEdit();
            }

            $roles->deleteRole($role);
            $this->forgetRoleCaches();

            Flux::toast(variant: 'success', text: __('Role deleted.'));
        }
    }

    private function isProtected(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_ROLES, true);
    }

    /**
     * Drop the memoised role computeds after a mutation so the list, per-role
     * permissions and the editable/deletable sets recompute.
     */
    private function forgetRoleCaches(): void
    {
        unset(
            $this->roles,
            $this->permissionsByRole,
            $this->deletableRoleIds,
            $this->editableRoleIds,
            $this->editingRole,
        );
    }

    public function render(): View
    {
        return view('livewire.projects.project-roles');
    }
}
