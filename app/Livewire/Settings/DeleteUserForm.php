<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        // Delete while still authenticated so the audit trail attributes the
        // removal to the user themselves, then end the session. The guard's
        // logout cycles a non-empty remember token with a save() — on the
        // already-deleted model that would re-insert the row — so clear it
        // before logging out.
        $user = Auth::user();
        $user->forceDelete();
        $user->setRememberToken('');
        $logout();

        $this->redirect('/', navigate: true);
    }
}
