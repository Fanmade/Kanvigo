<?php

namespace App\Livewire\Users;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Queries\UserProfileActivity;
use App\Support\ActivityDescriber;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A user's profile page: their name, avatar, the projects they share with the
 * viewer, and their recent activity in projects the viewer can see. Visibility
 * is governed by {@see UserPolicy::view()} — only the user
 * themselves, members who share a project, and access-all-projects holders may
 * open it. Built to grow (an "about" text, statistics, contact options) without
 * widening who can see it.
 *
 * @property-read EloquentCollection<int, Activity> $activities
 */
#[Title('Profile')]
class UserProfile extends Component
{
    /**
     * The opaque public id of the profile being viewed — kept (instead of the
     * numeric key) so the sequential id never reaches the client. Locked so a
     * tampered request can't swap it for another user; every computed
     * re-authorizes against it (KAN — IDOR).
     */
    #[Locked]
    public string $publicId;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);

        $this->publicId = $user->public_id;
    }

    /**
     * The profile's owner. Re-authorized on every resolve so the page stays
     * guarded even if the locked id were tampered with.
     */
    #[Computed]
    public function user(): User
    {
        $user = User::where('public_id', $this->publicId)->firstOrFail();

        $this->authorize('view', $user);

        return $user;
    }

    /**
     * The projects the viewer and the profile's owner have in common (every
     * project the owner belongs to when the viewer can access all projects),
     * ordered by name.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function sharedProjects(): EloquentCollection
    {
        $query = $this->user()->projects()->orderBy('name');

        if (! Auth::user()->canAccessAllProjects()) {
            $query->whereIn('projects.id', Auth::user()->projects()->select('projects.id'));
        }

        return $query->get();
    }

    /**
     * The owner's recent activity, scoped to subjects the viewer may see.
     *
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activities(): EloquentCollection
    {
        return (new UserProfileActivity)->handle($this->user(), Auth::user());
    }

    /**
     * Human-readable description line for each activity, keyed by activity id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function descriptions(): array
    {
        return $this->activities
            ->mapWithKeys(static fn (Activity $activity): array => [$activity->id => ActivityDescriber::describe($activity)])
            ->all();
    }

    /**
     * The link to an activity's subject (the task or project it was recorded on),
     * or null for a subject that exposes no page.
     */
    public function subjectUrl(Activity $activity): ?string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof Task => route('task.show', [
                'short_name' => $subject->project->short_name,
                'task_number' => $subject->task_number,
            ]),
            $subject instanceof Project => route('project.show', ['short_name' => $subject->short_name]),
            default => null,
        };
    }
}
