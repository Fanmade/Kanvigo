<?php

namespace App\Livewire\Invitations;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Project;
use App\Support\Facades\Audit;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, Project> $inviterProjects
 */
#[Title('Invite a user')]
class InviteUser extends Component
{
    public string $email = '';

    /** @var array<int, int> */
    public array $projectIds = [];

    public function mount(): void
    {
        $this->authorize('invite-users');
    }

    /**
     * The projects the inviter may grant access to: those where they hold the
     * project-scoped `invite-members` permission. Holding the account-level
     * `invite-users` grant alone is not enough to invite someone into a project
     * the inviter only has read/contributor access to.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function inviterProjects(): Collection
    {
        $user = Auth::user();

        return $user->projects()
            ->orderBy('title')
            ->get()
            ->filter(static fn (Project $project): bool => $user->hasScopedPermission('invite-members', $project))
            ->values();
    }

    public function sendInvitation(): void
    {
        $this->authorize('invite-users');

        $validated = $this->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'projectIds' => ['array'],
            'projectIds.*' => ['integer'],
        ]);

        // Only grant projects the inviter may invite members into (scoped
        // invite-members), not merely every project they belong to.
        $allowed = $this->inviterProjects->pluck('id');
        $grant = collect($this->projectIds)
            ->map(static fn ($id) => (int) $id)
            ->intersect($allowed)
            ->values()
            ->all();

        $token = Str::random(40);

        $invitation = new Invitation(['email' => $validated['email']]);
        $invitation->forceFill([
            'token' => $token,
            'invited_by' => Auth::id(),
            'project_ids' => $grant,
            'expires_at' => now()->addDays(7),
        ])->save();

        Audit::record(AuditEvent::make('invitation_created', AuditCategory::Authz)
            ->withSubject($invitation->getMorphClass(), $invitation->getKey())
            ->withMetadata(['email' => $invitation->email, 'project_ids' => $grant]));

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $token));

        $this->reset('email', 'projectIds');

        Flux::toast(text: __('Invitation sent.'), variant: 'success');
    }
}
