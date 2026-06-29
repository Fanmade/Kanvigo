<?php

namespace App\Actions;

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for project creation, shared by the web dialog, the
 * MCP tool and the REST API. Creates the project, makes the given user its owner
 * (membership + the owner role) and seeds the default task types.
 */
class CreateProject
{
    public function __construct(private readonly ProjectRoleProvisioner $provisioner) {}

    public function handle(User $owner, string $title, string $shortName, ?string $description = null): Project
    {
        // Provision the project, its owner membership/role and default task types
        // atomically, so a mid-way failure never leaves a half-provisioned project.
        return DB::transaction(function () use ($owner, $title, $shortName, $description): Project {
            $project = Project::create([
                'title' => $title,
                'short_name' => $shortName,
                'description' => $description,
            ]);

            $project->members()->attach($owner->getKey());
            $this->provisioner->syncMember($project, $owner, 'owner');
            TaskType::provisionDefaults($project);

            return $project;
        });
    }
}
