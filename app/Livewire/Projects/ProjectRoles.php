<?php

namespace App\Livewire\Projects;

use App\Authorization\PermissionCatalog;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use App\Support\Facades\Audit;
use Fanmade\DelegatedPermissions\DelegatedPermissions;
use Fanmade\DelegatedPermissions\Exceptions\DelegatedPermissionsException;
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

    /**
     * The base roles that stay code-owned. `owner` holds the whole catalog and
     * anchors both `manage-roles` and the delegation tree, so it is never edited
     * here; admin/member/viewer are seeded from {@see ProjectRoleProvisioner::GRANTS}
     * and may then be tuned per project.
     */
    private const array FIXED_ROLES = ['owner'];

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

    /** Whether the "move under…" panel is open for the selected role. */
    public bool $moving = false;

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
     * The ids of roles whose permissions the manager may edit: strictly below
     * them (visible but not one of their own — a manager never re-permissions a
     * role they hold) and not one of the code-owned roles.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function editableRoleIds(): Collection
    {
        $heldIds = Auth::user()->rolesIn($this->project)->pluck('id');

        return $this->roles()
            ->reject(fn (Role $role): bool => $this->isFixedRole($role) || $heldIds->contains($role->id))
            ->pluck('id');
    }

    /**
     * The ids of roles the manager may rename or delete — the editable custom
     * ones. Base role names are addressed from code (member syncing, the owner
     * rule, invitations), so all four keep their name and cannot be removed.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function removableRoleIds(): Collection
    {
        return $this->roles()
            ->filter(fn (Role $role): bool => ! $this->isBaseRole($role) && $this->editableRoleIds()->contains($role->id))
            ->pluck('id');
    }

    /**
     * Whether the selected role's permissions may be edited here.
     */
    #[Computed]
    public function canEditSelected(): bool
    {
        $role = $this->selectedRole();

        return $role !== null && $this->editableRoleIds()->contains($role->id);
    }

    /**
     * Whether the selected role may be renamed and deleted (custom roles only).
     */
    #[Computed]
    public function canRemoveSelected(): bool
    {
        $role = $this->selectedRole();

        return $role !== null && $this->removableRoleIds()->contains($role->id);
    }

    /**
     * Whether the selected role can be restored to its seeded permission set.
     */
    #[Computed]
    public function canResetSelected(): bool
    {
        $role = $this->selectedRole();

        return $role !== null
            && $this->canEditSelected()
            && array_key_exists($role->name, ProjectRoleProvisioner::GRANTS);
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

        if ($this->isFixedRole($role)) {
            return __('The owner role holds every permission by design and cannot be edited.');
        }

        return __('You cannot edit a role you hold yourself.');
    }

    /**
     * What resetting the selected role does, spelled out for the confirmation.
     */
    #[Computed]
    public function resetConsequence(): string
    {
        $role = $this->selectedRole();

        return $role === null
            ? ''
            : __('Restore :role to its default permissions? Any permission removed by this is also removed from the roles beneath it.', ['role' => $role->name]);
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

        $canRename = $this->canRemoveSelected();

        $validated = $this->validate([
            ...$canRename ? ['editName' => ['required', 'string', 'max:255']] : [],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editPermissionIds' => ['array'],
            'editPermissionIds.*' => ['integer'],
        ]);

        // A base role's name is addressed from code, so it is never renamed here.
        $newName = $canRename ? $validated['editName'] : $role->name;

        if ($canRename && $this->nameTaken($newName, $role->id)) {
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

        $renamedFrom = $role->name !== $newName ? $role->name : null;
        $describedChanged = (string) $role->description !== (string) $validated['editDescription'];

        if ($renamedFrom !== null || $describedChanged) {
            $roles->updateRole($role, [
                'name' => $newName,
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
     * Restore the selected base role to the permission set it is seeded with,
     * applying the difference through grant/revoke so the revokes cascade to
     * descendants. Defaults the role's parent no longer holds are skipped rather
     * than forced, keeping the delegation bound intact.
     */
    public function resetToDefaults(PermissionResolver $resolver): void
    {
        $this->authorize('manage-roles', $this->project);

        $role = $this->selectedRole();

        if ($role === null || ! $this->canResetSelected()) {
            return;
        }

        $parent = $role->parent;
        $allowed = $parent === null ? collect(ProjectRoleProvisioner::CATALOG) : $resolver->permissionsFor($parent);

        $defaults = collect(ProjectRoleProvisioner::GRANTS[$role->name]);
        $desired = $defaults->filter(static fn (string $name): bool => $allowed->contains($name))->values();
        $skipped = $defaults->diff($desired)->values();

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

        if ($granted->isNotEmpty() || $revoked->isNotEmpty()) {
            Audit::record(AuditEvent::make('role_updated', AuditCategory::Authz)
                ->withSubject($this->project->getMorphClass(), $this->project->getKey())
                ->withMetadata(array_filter([
                    'role' => $role->name,
                    'reset' => true,
                    'granted' => $granted->all(),
                    'revoked' => $revoked->all(),
                    'skipped' => $skipped->all(),
                ], static fn (mixed $value): bool => $value !== [])));
        }

        $this->closeForms();
        $this->forgetRoleCaches();

        Flux::toast(text: __('Role restored to its defaults.'), variant: 'success');
    }

    /**
     * Where the selected role could be re-parented to: every visible role in the
     * project except itself, its own descendants (that would be a cycle) and the
     * parent it already sits under. Each candidate carries the permissions the
     * moved subtree holds but the candidate does not — non-empty means the
     * package would reject the move, and the manager has to revoke them first.
     *
     * @return list<array{role: Role, exceeding: list<string>}>
     */
    #[Computed]
    public function moveTargets(): array
    {
        $role = $this->selectedRole();

        if ($role === null || ! $this->canRemoveSelected()) {
            return [];
        }

        $resolver = app(PermissionResolver::class);
        $subtree = $resolver->permissionsInSubtree($role);
        $excluded = [$role->id, ...$this->descendantIds($role)];

        $targets = [];

        foreach ($this->roleTree() as $node) {
            $candidate = $node['role'];

            if (in_array($candidate->id, $excluded, true) || $candidate->id === $role->parent_id) {
                continue;
            }

            $allowed = $resolver->permissionsFor($candidate);

            $targets[] = [
                'role' => $candidate,
                'exceeding' => array_values($subtree
                    ->reject(static fn (string $permission): bool => $allowed->contains($permission))
                    ->all()),
            ];
        }

        return $targets;
    }

    /**
     * The ids of the role's descendants among the visible roles.
     *
     * @return list<int>
     */
    private function descendantIds(Role $role): array
    {
        $childrenByParent = $this->roles()->groupBy(static fn (Role $node): int => $node->parent_id ?? 0);

        $ids = [];
        $queue = [$role->id];

        while ($queue !== []) {
            $parentId = array_pop($queue);

            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    public function startMove(): void
    {
        $this->authorize('manage-roles', $this->project);

        if (! $this->canRemoveSelected()) {
            return;
        }

        $this->editing = false;
        $this->creating = false;
        $this->moving = true;
        $this->resetErrorBag();
    }

    public function cancelMove(): void
    {
        $this->reset('moving');
    }

    /**
     * Re-parent the selected role under another visible role. The package
     * enforces the structural invariants and rejects a move whose subtree holds
     * more than the new parent — nothing is ever silently revoked, so the
     * offending permissions are reported back instead.
     */
    public function moveRole(RoleManager $roles, int $targetId): void
    {
        $project = $this->project;
        $this->authorize('manage-roles', $project);

        $role = $this->selectedRole();

        if ($role === null || ! $this->canRemoveSelected()) {
            return;
        }

        $target = collect($this->moveTargets())->firstWhere('role.id', $targetId);

        if ($target === null) {
            return;
        }

        $from = $role->parent?->name;

        try {
            $roles->moveRole($role, $target['role']);
        } catch (DelegatedPermissionsException $exception) {
            $this->addError('moving', $exception->getMessage());

            return;
        }

        Audit::record(AuditEvent::make('role_moved', AuditCategory::Authz)
            ->withSubject($project->getMorphClass(), $project->getKey())
            ->withMetadata(array_filter([
                'role' => $role->name,
                'from' => $from,
                'to' => $target['role']->name,
            ], static fn (mixed $value): bool => $value !== null)));

        $this->cancelMove();
        $this->forgetRoleCaches();

        Flux::toast(text: __('Role moved.'), variant: 'success');
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

        if ($role === null || ! $this->canRemoveSelected()) {
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
        $this->cancelMove();
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
            $this->removableRoleIds,
            $this->canEditSelected,
            $this->canRemoveSelected,
            $this->canResetSelected,
            $this->readOnlyReason,
            $this->resetConsequence,
            $this->moveTargets,
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

    /**
     * Whether the role is code-owned and therefore never edited here.
     */
    public function isFixedRole(Role $role): bool
    {
        return in_array($role->name, self::FIXED_ROLES, true);
    }

    public function render(): View
    {
        return view('livewire.projects.project-roles');
    }
}
