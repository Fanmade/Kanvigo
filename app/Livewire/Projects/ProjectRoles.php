<?php

namespace App\Livewire\Projects;

use App\Authorization\PermissionCatalog;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use App\Support\Facades\Audit;
use Fanmade\DelegatedPermissions\DelegatedPermissions;
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
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The per-project roles settings page: a master–detail view over the roles the
 * current manager may act on. The left pane lists the visible role tree by name
 * and hierarchy only; the right pane is the single editing surface — the role's
 * name and description, its permissions grouped per
 * {@see ProjectRoleProvisioner::GROUPS}, the members holding it, and the delete
 * and "add child role" actions. Restricted to holders of the project
 * `manage-roles` permission.
 *
 * Visibility is the package's delegation set ({@see User::visibleRoles()}): the
 * roles the manager holds and everything beneath them, never an ancestor and
 * never the system root. Editing is narrower still: only custom roles strictly
 * below the manager, and every permission change stays bounded by the role's
 * parent, so delegation can never escalate.
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

    /** Whether the detail pane is in edit mode for the selected role. */
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    /** @var array<int, int> */
    public array $editPermissionIds = [];

    /** Whether the "add child role" form is open under the selected role. */
    public bool $creating = false;

    public string $newName = '';

    /** @var array<int, int> */
    public array $newPermissionIds = [];

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
     * tampered id cannot reveal a role outside the delegation set). Any open
     * form belongs to the role that was showing, so it closes with it.
     */
    public function selectRole(int $roleId): void
    {
        if ($this->roles()->contains('id', $roleId)) {
            $this->selectedRoleId = $roleId;
            $this->closeForms();
        }
    }

    /**
     * The project permission catalog as Permission models, grouped for the
     * picker. Every group is offered; what the manager may actually grant is
     * bounded per role by {@see editAllowedPermissions()}.
     *
     * @return array<string, list<Permission>>
     */
    #[Computed]
    public function catalogGroups(): array
    {
        $byName = Permission::query()
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->get()
            ->keyBy('name');

        $groups = [];

        foreach (ProjectRoleProvisioner::GROUPS as $group => $names) {
            $permissions = [];

            foreach ($names as $name) {
                if ($byName->has($name)) {
                    $permissions[] = $byName->get($name);
                }
            }

            if ($permissions !== []) {
                $groups[$group] = $permissions;
            }
        }

        return $groups;
    }

    /**
     * The permissions the selected role's edit form may grant — its parent's
     * effective set, since a child is bounded by its parent.
     *
     * @return list<string>
     */
    #[Computed]
    public function editAllowedPermissions(): array
    {
        $parent = $this->selectedRole()?->parent;

        return $parent === null ? [] : array_values(app(PermissionResolver::class)->permissionsFor($parent)->all());
    }

    /**
     * The permissions a new child of the selected role may hold — the selected
     * role's own effective set.
     *
     * @return list<string>
     */
    #[Computed]
    public function createAllowedPermissions(): array
    {
        $role = $this->selectedRole();

        return $role === null ? [] : array_values(app(PermissionResolver::class)->permissionsFor($role)->all());
    }

    /**
     * The ids of roles the manager may edit or delete: strictly below them
     * (visible but not one of their own) and not a seeded base role. Base roles
     * stay code-owned and a manager may never edit a role they hold themselves.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function editableRoleIds(): Collection
    {
        $heldIds = Auth::user()->rolesIn($this->project)->pluck('id');

        return $this->roles()
            ->reject(fn (Role $role): bool => $this->isBaseRole($role) || $heldIds->contains($role->id))
            ->pluck('id');
    }

    /**
     * Whether the selected role may be edited and deleted here.
     */
    #[Computed]
    public function canEditSelected(): bool
    {
        $role = $this->selectedRole();

        return $role !== null && $this->editableRoleIds()->contains($role->id);
    }

    /**
     * Why the selected role is read-only, for the detail pane to state, or null
     * when it may be edited.
     */
    #[Computed]
    public function readOnlyReason(): ?string
    {
        $role = $this->selectedRole();

        if ($role === null || $this->canEditSelected()) {
            return null;
        }

        if ($this->isBaseRole($role)) {
            return __('Base roles are defined in code and cannot be edited.');
        }

        return __('You cannot edit a role you hold yourself.');
    }

    /**
     * What deleting the selected role does, spelled out for the confirmation:
     * where its children end up and how many members lose it.
     */
    #[Computed]
    public function deleteConsequence(): string
    {
        $role = $this->selectedRole();

        if ($role === null) {
            return '';
        }

        $members = $this->memberCounts()[$role->id] ?? 0;
        $parent = $this->selectedRoleParent();

        $consequence = $parent === null
            ? __('Delete the role :role?', ['role' => $role->name])
            : __('Delete the role :role? Its child roles move up under :parent.', ['role' => $role->name, 'parent' => $parent->name]);

        return $members === 0
            ? $consequence
            : $consequence.' '.trans_choice('{1}:count member loses it.|[2,*]:count members lose it.', $members, ['count' => $members]);
    }

    /**
     * Open the selected role for editing, seeding the form with its current
     * name, description and catalog permissions.
     */
    public function startEdit(): void
    {
        $this->authorize('manage-roles', $this->project);

        $role = $this->selectedRole();

        if ($role === null || ! $this->canEditSelected()) {
            return;
        }

        $held = app(PermissionResolver::class)->permissionsFor($role);

        $this->creating = false;
        $this->editing = true;
        $this->editName = $role->name;
        $this->editDescription = (string) $role->description;
        $this->editPermissionIds = $this->catalogPermissionIds($held);

        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editName', 'editDescription', 'editPermissionIds');
        $this->resetErrorBag();
    }

    /**
     * Apply the edited name, description and permission set to the selected
     * role. Additions are bounded by the role's parent (the picker disables the
     * rest, this is the safety net against a tampered id); revokes cascade to
     * descendants.
     */
    public function saveRole(PermissionResolver $resolver, RoleManager $roles): void
    {
        $this->authorize('manage-roles', $this->project);

        $role = $this->selectedRole();

        if ($role === null || ! $this->canEditSelected()) {
            return;
        }

        $parent = $role->parent;

        if ($parent === null) {
            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editPermissionIds' => ['array'],
            'editPermissionIds.*' => ['integer'],
        ]);

        if ($this->nameTaken($validated['editName'], $role->id)) {
            $this->addError('editName', __('A role with that name already exists.'));

            return;
        }

        $allowed = $resolver->permissionsFor($parent);

        $desired = $this->permissionNamesFor($this->editPermissionIds)
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values();

        $current = $resolver->permissionsFor($role)
            ->filter(static fn (string $name): bool => in_array($name, ProjectRoleProvisioner::CATALOG, true))
            ->map(static fn (string $name): string => $name)
            ->values();

        $granted = $desired->diff($current)->values();
        $revoked = $current->diff($desired)->values();

        foreach ($granted as $name) {
            $resolver->grant($role, $name);
        }

        foreach ($revoked as $name) {
            $resolver->revoke($role, $name);
        }

        $renamedFrom = $role->name !== $validated['editName'] ? $role->name : null;
        $describedChanged = (string) $role->description !== (string) $validated['editDescription'];

        if ($renamedFrom !== null || $describedChanged) {
            $roles->updateRole($role, [
                'name' => $validated['editName'],
                'description' => $validated['editDescription'] === '' ? null : $validated['editDescription'],
            ]);
        }

        if ($granted->isNotEmpty() || $revoked->isNotEmpty() || $renamedFrom !== null || $describedChanged) {
            Audit::record(AuditEvent::make('role_updated', AuditCategory::Authz)
                ->withSubject($this->project->getMorphClass(), $this->project->getKey())
                ->withMetadata(array_filter([
                    'role' => $role->name,
                    'renamed_from' => $renamedFrom,
                    'granted' => $granted->all(),
                    'revoked' => $revoked->all(),
                ], static fn (mixed $value): bool => $value !== [] && $value !== null)));
        }

        $this->cancelEdit();
        $this->forgetRoleCaches();

        Flux::toast(text: __('Role updated.'), variant: 'success');
    }

    /**
     * Open the "add child role" form under the selected role, which becomes the
     * new role's parent and its permission bound.
     */
    public function startCreate(): void
    {
        $this->authorize('manage-roles', $this->project);

        if ($this->selectedRole() === null) {
            return;
        }

        $this->editing = false;
        $this->creating = true;
        $this->reset('newName', 'newPermissionIds');
        $this->resetErrorBag();
    }

    public function cancelCreate(): void
    {
        $this->reset('creating', 'newName', 'newPermissionIds');
        $this->resetErrorBag();
    }

    /**
     * Prefill the new role's permissions with those of another visible role,
     * bounded by the parent the form is fixed to.
     */
    public function copyPermissionsFrom(int $roleId, PermissionResolver $resolver): void
    {
        $this->authorize('manage-roles', $this->project);

        $source = $this->roles()->firstWhere('id', $roleId);
        $parent = $this->selectedRole();

        if ($source === null || $parent === null) {
            return;
        }

        $allowed = $resolver->permissionsFor($parent);

        $this->newPermissionIds = $this->catalogPermissionIds(
            $resolver->permissionsFor($source)->filter(static fn (string $name): bool => $allowed->contains($name)),
        );
    }

    /**
     * Create a child role under the selected one, bounded by its permissions,
     * and open the new role in the detail pane.
     */
    public function createRole(RoleManager $roles, PermissionResolver $resolver): void
    {
        $project = $this->project;
        $this->authorize('manage-roles', $project);

        $parent = $this->selectedRole();

        if ($parent === null) {
            return;
        }

        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newPermissionIds' => ['array'],
            'newPermissionIds.*' => ['integer'],
        ]);

        if ($this->nameTaken($validated['newName'])) {
            $this->addError('newName', __('A role with that name already exists.'));

            return;
        }

        // Bound the chosen permissions to the parent (the picker already disables
        // the rest, this is the safety net so a tampered id can't escalate).
        $allowed = $resolver->permissionsFor($parent);
        $names = $this->permissionNamesFor($this->newPermissionIds)
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values()
            ->all();

        $role = $roles->createRole($validated['newName'], $parent, $names, $project);

        Audit::record(AuditEvent::make('role_created', AuditCategory::Authz)
            ->withSubject($project->getMorphClass(), $project->getKey())
            ->withMetadata(['role' => $validated['newName'], 'parent' => $parent->name, 'permissions' => $names]));

        $this->cancelCreate();
        $this->forgetRoleCaches();
        $this->selectedRoleId = $role->id;

        Flux::toast(text: __('Role created.'), variant: 'success');
    }

    /**
     * Delete the selected role. Its children move up under its parent (their
     * permissions are already a subset of that grandparent's), and the pane
     * falls back to the parent.
     */
    public function deleteRole(RoleManager $roles): void
    {
        $project = $this->project;
        $this->authorize('manage-roles', $project);

        $role = $this->selectedRole();

        if ($role === null || ! $this->canEditSelected()) {
            return;
        }

        $parentId = $role->parent_id;

        $roles->deleteRole($role);

        Audit::record(AuditEvent::make('role_deleted', AuditCategory::Authz)
            ->withSubject($project->getMorphClass(), $project->getKey())
            ->withMetadata(['role' => $role->name]));

        $this->closeForms();
        $this->forgetRoleCaches();
        $this->selectedRoleId = $this->roles()->contains('id', $parentId) ? $parentId : null;

        Flux::toast(text: __('Role deleted.'), variant: 'success');
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
     * The short label for a permission in the picker, where the group heading
     * already names the subject. Defers to {@see PermissionCatalog}.
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
     * Whether another role in this project already carries the name.
     */
    private function nameTaken(string $name, ?int $exceptRoleId = null): bool
    {
        return Role::query()
            ->where('scope_type', $this->project->getMorphClass())
            ->where('scope_id', $this->project->id)
            ->where('name', $name)
            ->when($exceptRoleId !== null, static fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();
    }

    /**
     * The catalog permission ids for the given permission names, for seeding a
     * picker from a role's effective set.
     *
     * @param  Collection<int, string>  $names
     * @return array<int, int>
     */
    private function catalogPermissionIds(Collection $names): array
    {
        return Permission::query()
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->whereIn('name', $names->values()->all())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The names of the given permission ids, restricted to the project catalog.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, string>
     */
    private function permissionNamesFor(array $ids): Collection
    {
        return Permission::query()
            ->whereKey($ids)
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->pluck('name');
    }

    private function closeForms(): void
    {
        $this->cancelEdit();
        $this->cancelCreate();
    }

    /**
     * Drop the memoised role computeds after a mutation so the tree, the
     * member counts and the editable set recompute.
     */
    private function forgetRoleCaches(): void
    {
        unset(
            $this->roles,
            $this->roleTree,
            $this->memberCounts,
            $this->deleteConsequence,
            $this->editableRoleIds,
            $this->canEditSelected,
            $this->readOnlyReason,
            $this->selectedRole,
            $this->selectedRoleParent,
            $this->selectedRolePermissionGroups,
            $this->selectedRoleMembers,
            $this->editAllowedPermissions,
            $this->createAllowedPermissions,
        );
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
