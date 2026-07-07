<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    |
    | An optional prefix prepended to every package table name below, so the
    | package can coexist with conflicting tables in the host app (e.g. set it
    | to "dp_" for dp_roles, dp_permissions, …).
    |
    */

    'table_prefix' => env('DELEGATED_PERMISSIONS_TABLE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Gate integration
    |--------------------------------------------------------------------------
    |
    | When true, a Gate "before" hook lets permission checks flow through the
    | resolver, so `$user->can('manage-tags', $project)` works out of the box
    | (the first model argument is taken as the scope). Disable it to keep gate
    | checks untouched and call `hasPermission()` directly instead.
    |
    | Disabled here: the app registers its own equivalent hook (see
    | AppServiceProvider::registerPermissionGate()) that enforces the API token
    | project scope before delegating to the resolver. The package hook would
    | grant abilities before that scope check could deny them.
    |
    */

    'register_gate' => false,

    /*
    |--------------------------------------------------------------------------
    | Roles per scope
    |--------------------------------------------------------------------------
    |
    | How many roles a single authorizable may hold within one scope. A user may
    | hold several project roles at once (e.g. Designer + Reviewer); their
    | effective permissions are the union. Use a positive integer to cap it, or
    | null/-1 for no limit. The system role is always exempt. We leave project
    | scope unlimited and enforce the one-owner-per-project rule app-side, in the
    | ProjectRoleProvisioner.
    |
    */

    'max_roles_per_scope' => env('DELEGATED_PERMISSIONS_MAX_ROLES_PER_SCOPE'),

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Base names for the package's tables (the prefix above is prepended).
    |
    */

    'tables' => [
        'permissions' => 'permissions',
        'permission_groups' => 'permission_groups',
        'permission_group_permission' => 'permission_group_permission',
        'roles' => 'roles',
        'permission_role' => 'permission_role',
        'role_assignments' => 'role_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | System role
    |--------------------------------------------------------------------------
    |
    | The root role of every scope's tree: it implicitly holds every permission
    | and is the only role without a parent. It is meant for initial setup and
    | break-glass fixes — disable it (via the env toggle) once real admin roles
    | exist, after which it grants nothing.
    |
    */

    'system' => [
        // Master switch. When false, the system role grants nothing and its
        // above-all access is off. Flip it off after initial setup.
        'enabled' => env('DELEGATED_PERMISSIONS_SYSTEM_ENABLED', true),

        // The name of the root role.
        'role' => 'system',

        // When enabled, the system scope sits above every other scope, so a
        // system role reaches any scope by default (break-glass).
        'scope_above_all' => true,
    ],

];
