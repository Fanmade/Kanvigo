<?php

namespace App\Livewire\Settings;

use App\Enums\TokenAbility;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('API tokens')]
class ApiTokens extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|in:read,write')]
    public string $accessLevel = 'read';

    #[Locked]
    public ?string $plainTextToken = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create-api-tokens');
    }

    /**
     * Create a new personal access token for the authenticated user.
     */
    public function createToken(): void
    {
        $this->validate();

        $abilities = TokenAbility::abilitiesFor(TokenAbility::from($this->accessLevel));

        $this->plainTextToken = Auth::user()
            ->createToken($this->name, $abilities)
            ->plainTextToken;

        $this->reset('name', 'accessLevel');

        unset($this->tokens);

        Flux::toast(variant: 'success', text: __('API token created.'));
    }

    /**
     * Revoke one of the authenticated user's tokens.
     */
    public function revoke(int $tokenId): void
    {
        Auth::user()->tokens()->whereKey($tokenId)->delete();

        unset($this->tokens);

        Flux::toast(variant: 'success', text: __('API token revoked.'));
    }

    /**
     * Dismiss the freshly created plain-text token.
     */
    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    /**
     * Get the authenticated user's tokens mapped for display.
     *
     * @return array<int, array{id: int, name: string, abilities_label: string, last_used_at_diff: string|null, created_at_diff: string}>
     */
    #[Computed]
    public function tokens(): array
    {
        return Auth::user()->tokens()
            ->select(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
            ->latest()
            ->get()
            ->map(static fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities_label' => in_array(TokenAbility::Write->value, $token->abilities ?? [], true)
                    ? TokenAbility::Write->label()
                    : TokenAbility::Read->label(),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                'created_at_diff' => $token->created_at->diffForHumans(),
            ])
            ->all();
    }
}
