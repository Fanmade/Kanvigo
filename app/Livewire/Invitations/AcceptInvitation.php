<?php

namespace App\Livewire\Invitations;

use App\Concerns\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::auth')]
#[Title('Accept invitation')]
class AcceptInvitation extends Component
{
    use PasswordValidationRules;

    public Invitation $invitation;

    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(Invitation $invitation): void
    {
        $this->invitation = $invitation;
        $this->token = (string) request()->query('token', '');

        abort_unless($this->invitationIsAcceptable(), 403);
    }

    /**
     * The invitation must be unused, unexpired, and the token must match.
     */
    protected function invitationIsAcceptable(): bool
    {
        return ! $this->invitation->isAccepted()
            && ! $this->invitation->isExpired()
            && hash_equals($this->invitation->token, $this->token);
    }

    public function accept(): void
    {
        abort_unless($this->invitationIsAcceptable(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $this->invitation->email,
                'password' => $validated['password'],
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->projects()->sync($this->invitation->project_ids);

            $this->invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);

        $this->redirectRoute('security.edit', navigate: false);
    }
}
