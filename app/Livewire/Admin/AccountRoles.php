<?php

namespace App\Livewire\Admin;

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission as AccountPermission;
use App\Models\User;
use App\Queries\NamedAccountRoles;
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
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Named account-level roles: a master–detail page over the global-scope roles
 * that bundle several account permissions under one name (e.g. "User manager" =
 * invite-users + manage-users). Assigning them happens in User administration,
 * next to the single-permission chips.
 *
 * Unlike the project page this is deliberately flat rather than delegated. The
 * global tree holds one single-permission role per {@see AccountPermission} —
 * the plumbing behind the chips (see App\\Authorization\\AccountPermissionProvisioner) — and a
 * named role cannot be a child of any of them, since a child is bounded by its
 * parent. Named roles therefore hang off the system role as siblings of the
 * chip roles, where the package's delegation visibility (own roles plus their
 * descendants) would never surface them. Holders of `manage-account-roles`
 * simply administer them all; the chip roles stay hidden as implementation
 * detail.
 */
#[Title('Account roles')]
class AccountRoles extends Component
{
    use AuthorizesRequests;

    /** The role open in the detail pane, deep-linked as `?role=`. */
    #[Url(as: 'role')]
    public ?int $selectedRoleId = null;

    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    /** @var array<int, int> */
    public array $editPermissionIds = [];

    /**
     * Whether the create form is open, and the role it nests the new role under
     * — null creates a top-level role directly under the system root.
     */
    public bool $creating = false;

    public ?int $creatingParentId = null;

    public string $newName = '';

    /** @var array<int, int> */
    public array $newPermissionIds = [];

    public function mount(): void
    {
        $this->authorize('manage-account-roles');
    }

    /**
     * The named global roles: every non-system role in the global scope except
     * the single-permission roles backing the permission chips.
     *
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function roles(): EloquentCollection
    {
        $this->authorize('manage-account-roles');

        return app(NamedAccountRoles::class)->handle();
    }

    /**
     * The named roles in hierarchy order with their depth, so a role delegated
     * under another indents beneath it.
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

        // A named role whose parent is the system root (or another hidden role)
        // is a root of the visible tree.
        foreach ($roles as $role) {
            if ($role->parent_id === null || ! $byId->has($role->parent_id)) {
                $visit($role, 0);
            }
        }

        return $ordered;
    }

    /**
     * How many users hold each named role, keyed by role id, in one query.
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

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->roles()->firstWhere('id', $this->selectedRoleId)
            ?? ($this->roleTree()[0]['role'] ?? null);
    }

    /**
     * The selected role's parent when it is a named role — a role hanging off
     * the system root has no parent to show.
     */
    #[Computed]
    public function selectedRoleParent(): ?Role
    {
        $role = $this->selectedRole();

        return $role === null ? null : $this->roles()->firstWhere('id', $role->parent_id);
    }

    /**
     * The account permissions the selected role effectively holds, labelled.
     *
     * @return list<string>
     */
    #[Computed]
    public function selectedRolePermissions(): array
    {
        $role = $this->selectedRole();

        if ($role === null) {
            return [];
        }

        $held = app(PermissionResolver::class)->permissionsFor($role);

        return array_values(array_map(
            fn (string $name): string => $this->permissionLabel($name),
            array_filter($this->catalog(), static fn (string $name): bool => $held->contains($name)),
        ));
    }

    /**
     * The users holding the selected role.
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
     * The account permission catalog as one picker group.
     *
     * @return array<string, list<Permission>>
     */
    #[Computed]
    public function catalogGroups(): array
    {
        $permissions = Permission::query()
            ->whereIn('name', $this->catalog())
            ->get()
            ->keyBy('name');

        $group = [];

        foreach ($this->catalog() as $name) {
            if ($permissions->has($name)) {
                $group[] = $permissions->get($name);
            }
        }

        return $group === [] ? [] : ['Account' => $group];
    }

    /**
     * What the selected role may hold — its parent's set, or the whole account
     * catalog when it hangs off the system root.
     *
     * @return list<string>
     */
    #[Computed]
    public function editAllowedPermissions(): array
    {
        return $this->allowedUnder($this->selectedRoleParent());
    }

    /**
     * What a new role may hold: bounded by the role it is nested under, or the
     * whole catalog for a top-level role.
     *
     * @return list<string>
     */
    #[Computed]
    public function createAllowedPermissions(): array
    {
        return $this->allowedUnder($this->creatingParent());
    }

    /**
     * The role the create form nests the new role under, if any.
     */
    #[Computed]
    public function creatingParent(): ?Role
    {
        return $this->creatingParentId === null
            ? null
            : $this->roles()->firstWhere('id', $this->creatingParentId);
    }

    public function selectRole(int $roleId): void
    {
        if ($this->roles()->contains('id', $roleId)) {
            $this->selectedRoleId = $roleId;
            $this->closeForms();
        }
    }

    public function startEdit(): void
    {
        $this->authorize('manage-account-roles');

        $role = $this->selectedRole();

        if ($role === null) {
            return;
        }

        $held = app(PermissionResolver::class)->permissionsFor($role);

        $this->creating = false;
        $this->editing = true;
        $this->editName = $role->name;
        $this->editDescription = (string) $role->description;
        $this->editPermissionIds = $this->permissionIdsFor($held);

        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editName', 'editDescription', 'editPermissionIds');
        $this->resetErrorBag();
    }

    /**
     * Apply the edited name, description and permission set. Additions are
     * bounded by the role's parent; revokes cascade to its descendants.
     */
    public function saveRole(PermissionResolver $resolver, RoleManager $roles): void
    {
        $this->authorize('manage-account-roles');

        $role = $this->selectedRole();

        if ($role === null) {
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

        $allowed = collect($this->allowedUnder($this->selectedRoleParent()));

        $desired = $this->permissionNamesFor($this->editPermissionIds)
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values();

        $current = $resolver->permissionsFor($role)
            ->filter(fn (string $name): bool => in_array($name, $this->catalog(), true))
            ->map(static fn (string $name): string => $name)
            ->values();

        $granted = $desired->diff($current)->values();
        $revoked = $current->diff($desired)->values();

        foreach ($granted as $permission) {
            $resolver->grant($role, $permission);
        }

        foreach ($revoked as $permission) {
            $resolver->revoke($role, $permission);
        }

        $renamedFrom = $role->name !== $validated['editName'] ? $role->name : null;
        $descriptionChanged = (string) $role->description !== (string) $validated['editDescription'];

        if ($renamedFrom !== null || $descriptionChanged) {
            $roles->updateRole($role, [
                'name' => $validated['editName'],
                'description' => $validated['editDescription'] === '' ? null : $validated['editDescription'],
            ]);
        }

        if ($granted->isNotEmpty() || $revoked->isNotEmpty() || $renamedFrom !== null || $descriptionChanged) {
            Audit::record(AuditEvent::make('account_role_updated', AuditCategory::Authz)
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
     * Open the create form, either for a top-level role or nested under the
     * given named role.
     */
    public function startCreate(?int $parentId = null): void
    {
        $this->authorize('manage-account-roles');

        if ($parentId !== null && ! $this->roles()->contains('id', $parentId)) {
            return;
        }

        $this->editing = false;
        $this->creating = true;
        $this->creatingParentId = $parentId;
        $this->reset('newName', 'newPermissionIds');
        $this->resetErrorBag();
    }

    public function cancelCreate(): void
    {
        $this->reset('creating', 'creatingParentId', 'newName', 'newPermissionIds');
        $this->resetErrorBag();
    }

    /**
     * Create a named role — under another named role, or under the system root
     * for a top-level one — and open it in the detail pane.
     */
    public function createRole(RoleManager $roles, ProjectRoleProvisioner $provisioner): void
    {
        $this->authorize('manage-account-roles');

        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newPermissionIds' => ['array'],
            'newPermissionIds.*' => ['integer'],
        ]);

        if ($this->nameTaken($validated['newName'])) {
            $this->addError('newName', __('A role with that name already exists.'));

            return;
        }

        $parent = $this->creatingParent();
        $allowed = collect($this->allowedUnder($parent));

        $names = $this->permissionNamesFor($this->newPermissionIds)
            ->filter(static fn (string $name): bool => $allowed->contains($name))
            ->values()
            ->all();

        $role = $roles->createRole($validated['newName'], $parent ?? $provisioner->systemRole(), $names);

        Audit::record(AuditEvent::make('account_role_created', AuditCategory::Authz)
            ->withMetadata(array_filter([
                'role' => $validated['newName'],
                'parent' => $parent?->name,
                'permissions' => $names,
            ], static fn (mixed $value): bool => $value !== [] && $value !== null)));

        $this->cancelCreate();
        $this->forgetRoleCaches();
        $this->selectedRoleId = $role->id;

        Flux::toast(text: __('Role created.'), variant: 'success');
    }

    /**
     * Delete the selected role; its children move up under its parent and every
     * assignment goes with it.
     */
    public function deleteRole(RoleManager $roles): void
    {
        $this->authorize('manage-account-roles');

        $role = $this->selectedRole();

        if ($role === null) {
            return;
        }

        $parentId = $role->parent_id;

        $roles->deleteRole($role);

        Audit::record(AuditEvent::make('account_role_deleted', AuditCategory::Authz)
            ->withMetadata(['role' => $role->name]));

        $this->closeForms();
        $this->forgetRoleCaches();
        $this->selectedRoleId = $this->roles()->contains('id', $parentId) ? $parentId : null;

        Flux::toast(text: __('Role deleted.'), variant: 'success');
    }

    /**
     * What deleting the selected role does, spelled out for the confirmation.
     */
    #[Computed]
    public function deleteConsequence(): string
    {
        $role = $this->selectedRole();

        if ($role === null) {
            return '';
        }

        $members = $this->memberCounts()[$role->id] ?? 0;

        $consequence = __('Delete the role :role?', ['role' => $role->name]);

        return $members === 0
            ? $consequence
            : $consequence.' '.trans_choice('{1}:count member loses it.|[2,*]:count members lose it.', $members, ['count' => $members]);
    }

    /**
     * The label for an account permission, from the enum.
     */
    public function permissionLabel(string $name): string
    {
        return AccountPermission::tryFrom($name)?->label() ?? $name;
    }

    public function permissionPickerLabel(string $name): string
    {
        return $this->permissionLabel($name);
    }

    /**
     * Account permissions carry no extra help text; the labels say it all.
     */
    public function permissionDescription(string $name): ?string
    {
        return null;
    }

    /**
     * The account permission catalog: every {@see AccountPermission} value.
     *
     * @return list<string>
     */
    private function catalog(): array
    {
        return array_map(
            static fn (AccountPermission $permission): string => $permission->value,
            AccountPermission::cases(),
        );
    }

    /**
     * What a role under the given parent may hold: the parent's own set, or the
     * whole account catalog when it hangs off the system root (which implicitly
     * holds everything).
     *
     * @return list<string>
     */
    private function allowedUnder(?Role $parent): array
    {
        if ($parent === null) {
            return $this->catalog();
        }

        $allowed = app(PermissionResolver::class)->permissionsFor($parent);

        return array_values(array_filter(
            $this->catalog(),
            static fn (string $name): bool => $allowed->contains($name),
        ));
    }

    private function nameTaken(string $name, ?int $exceptRoleId = null): bool
    {
        return Role::query()
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->where('name', $name)
            ->when($exceptRoleId !== null, static fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array<int, int>
     */
    private function permissionIdsFor(Collection $names): array
    {
        return Permission::query()
            ->whereIn('name', $this->catalog())
            ->whereIn('name', $names->values()->all())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, string>
     */
    private function permissionNamesFor(array $ids): Collection
    {
        return Permission::query()
            ->whereKey($ids)
            ->whereIn('name', $this->catalog())
            ->pluck('name');
    }

    private function closeForms(): void
    {
        $this->cancelEdit();
        $this->cancelCreate();
    }

    private function forgetRoleCaches(): void
    {
        unset(
            $this->roles,
            $this->roleTree,
            $this->memberCounts,
            $this->selectedRole,
            $this->selectedRoleParent,
            $this->selectedRolePermissions,
            $this->selectedRoleMembers,
            $this->editAllowedPermissions,
            $this->createAllowedPermissions,
            $this->creatingParent,
            $this->deleteConsequence,
        );
    }

    public function render(): View
    {
        return view('livewire.admin.account-roles');
    }
}
