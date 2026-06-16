<?php

namespace App\Livewire\Invitations;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Invite a user')]
class InviteUser extends Component
{
    public string $email = '';

    /** @var array<int, int> */
    public array $projectIds = [];

    public function mount(): void
    {
        Gate::authorize('invite-users');
    }

    /**
     * The projects the inviter may grant access to (their own).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function inviterProjects(): Collection
    {
        return Auth::user()->projects()->orderBy('title')->get();
    }

    public function sendInvitation(): void
    {
        Gate::authorize('invite-users');

        $validated = $this->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'projectIds' => ['array'],
            'projectIds.*' => ['integer'],
        ]);

        // Only grant projects the inviter actually has access to.
        $allowed = Auth::user()->projects()->pluck('projects.id');
        $grant = collect($this->projectIds)
            ->map(static fn ($id) => (int) $id)
            ->intersect($allowed)
            ->values()
            ->all();

        $token = Str::random(40);

        $invitation = Invitation::create([
            'email' => $validated['email'],
            'token' => $token,
            'invited_by' => Auth::id(),
            'project_ids' => $grant,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $token));

        $this->reset('email', 'projectIds');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }
}
