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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-project role management: view the project's roles and the permissions they
 * hold, and define custom roles by delegating a subset of the owner's permissions.
 * Restricted to holders of the project `manage-roles` permission.
 */
class ProjectRoles extends Component
{
    use AuthorizesRequests;

    /** The seeded base roles, which may not be deleted here. */
    private const array PROTECTED_ROLES = ['owner', 'admin', 'member'];

    #[Locked]
    public int $projectId;

    public string $name = '';

    /** @var array<int, int> */
    public array $permissionIds = [];

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->authorize('manage-roles', $project);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    /**
     * The project's roles, the seeded base first.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function roles(): \Illuminate\Support\Collection
    {
        return Role::query()
            ->where('scope_type', $this->project()->getMorphClass())
            ->where('scope_id', $this->projectId)
            ->get()
            ->sortBy(fn (Role $role): string => sprintf('%d-%s', $this->isProtected($role) ? 0 : 1, $role->name))
            ->values();
    }

    /**
     * The project permission catalog, offered when defining a role.
     *
     * @return Collection<int, Permission>
     */
    #[Computed]
    public function permissions(): Collection
    {
        return Permission::query()
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->orderBy('name')
            ->get();
    }

    /**
     * The effective permission names each role holds, keyed by role id.
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

    public function createRole(RoleManager $roles): void
    {
        $project = $this->project();
        $this->authorize('manage-roles', $project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissionIds' => ['array'],
            'permissionIds.*' => ['integer'],
        ]);

        if ($this->roles()->contains(static fn (Role $role): bool => $role->name === $validated['name'])) {
            $this->addError('name', __('A role with that name already exists.'));

            return;
        }

        $owner = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
        $names = Permission::query()->whereKey($validated['permissionIds'] ?? [])->pluck('name')->all();

        $roles->createRole($validated['name'], $owner, $names, $project);

        $this->reset('name', 'permissionIds');
        unset($this->roles, $this->permissionsByRole);

        Flux::toast(variant: 'success', text: __('Role created.'));
    }

    public function deleteRole(RoleManager $roles, int $roleId): void
    {
        $project = $this->project();
        $this->authorize('manage-roles', $project);

        $role = Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->id)
            ->whereKey($roleId)
            ->firstOrFail();

        if ($this->isProtected($role)) {
            return;
        }

        $roles->deleteRole($role);

        unset($this->roles, $this->permissionsByRole);

        Flux::toast(variant: 'success', text: __('Role deleted.'));
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
