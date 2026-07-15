<?php

namespace App\Livewire\Settings;

use App\Enums\Permission;
use App\Enums\TokenAbility;
use App\Models\McpClientGrant;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Support\Facades\Audit;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
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

    #[Validate('required|in:read,write,audit')]
    public string $accessLevel = 'read';

    #[Validate('required|in:all,selected')]
    public string $projectScope = 'all';

    /** @var array<int, string> */
    #[Validate(['selectedProjects' => 'array', 'selectedProjects.*' => 'integer'])]
    public array $selectedProjects = [];

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
        $this->authorize('create-api-tokens');

        $this->validate();

        $level = TokenAbility::from($this->accessLevel);

        // The audit stream is instance-wide, so it is gated on the account-level
        // manage-users permission and is never project-scoped.
        if ($level === TokenAbility::Audit && ! $this->canMintAuditTokens()) {
            throw ValidationException::withMessages([
                'accessLevel' => __('You do not have permission to create audit tokens.'),
            ]);
        }

        $restrictsProjects = $level !== TokenAbility::Audit && $this->projectScope === 'selected';

        $projectIds = $restrictsProjects ? $this->allowedSelectedProjectIds() : [];

        $abilities = TokenAbility::abilitiesFor($level);

        $newToken = Auth::user()->createToken($this->name, $abilities);

        Audit::record(AuditEvent::make('token_created', AuditCategory::Token)
            ->withSubject(Auth::user()->getMorphClass(), Auth::id())
            ->withMetadata([
                'token_id' => $newToken->accessToken->getKey(),
                'token' => $this->name,
                'abilities' => $abilities,
            ]));

        if ($restrictsProjects) {
            $accessToken = Auth::user()->tokens()->whereKey($newToken->accessToken->getKey())->firstOrFail();
            $accessToken->forceFill(['restricts_projects' => true])->save();
            $accessToken->projects()->attach($projectIds);
        }

        $this->plainTextToken = $newToken->plainTextToken;

        $this->reset('name', 'accessLevel', 'projectScope', 'selectedProjects');

        unset($this->tokens);

        Flux::toast(text: __('API token created.'), variant: 'success');
    }

    /**
     * The ids of the selected projects the user is actually a member of,
     * validating that the restricted scope is not empty. Selections outside
     * the user's memberships are rejected rather than silently dropped.
     *
     * @return array<int, int>
     */
    protected function allowedSelectedProjectIds(): array
    {
        $memberProjectIds = Auth::user()->projects()
            ->whereIn('projects.id', $this->selectedProjects)
            ->pluck('projects.id');

        if ($memberProjectIds->isEmpty() || $memberProjectIds->count() !== count(array_unique($this->selectedProjects))) {
            throw ValidationException::withMessages([
                'selectedProjects' => __('Select at least one of your projects.'),
            ]);
        }

        return $memberProjectIds->all();
    }

    /**
     * Revoke one of the authenticated user's tokens.
     */
    public function revoke(int $tokenId): void
    {
        $this->authorize('create-api-tokens');

        $token = Auth::user()->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return;
        }

        $token->delete();

        Audit::record(AuditEvent::make('token_revoked', AuditCategory::Token)
            ->withSubject(Auth::user()->getMorphClass(), Auth::id())
            ->withMetadata(['token_id' => $token->getKey(), 'token' => $token->name]));

        unset($this->tokens);

        Flux::toast(text: __('API token revoked.'), variant: 'success');
    }

    /**
     * Dismiss the freshly created plain-text token.
     */
    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    /**
     * Revoke an OAuth MCP connection: revoke every token the client holds for
     * this user and delete the consent grant, so the client must re-authorize
     * (and the user re-pick a project scope) to connect again.
     */
    public function revokeConnection(int $grantId): void
    {
        $this->authorize('create-api-tokens');

        $grant = McpClientGrant::query()
            ->where('user_id', Auth::id())
            ->whereKey($grantId)
            ->first();

        if ($grant === null) {
            return;
        }

        $tokenIds = Token::query()
            ->where('user_id', Auth::id())
            ->where('client_id', $grant->oauth_client_id)
            ->pluck('id');

        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        Token::query()->whereKey($tokenIds)->update(['revoked' => true]);

        Audit::record(AuditEvent::make('token_revoked', AuditCategory::Token)
            ->withSubject(Auth::user()->getMorphClass(), Auth::id())
            ->withMetadata(['token' => $grant->client->name, 'reason' => 'oauth_connection_revoked']));

        $grant->delete();

        unset($this->connections);

        Flux::toast(text: __('Connection revoked.'), variant: 'success');
    }

    /**
     * The user's OAuth MCP connections (e.g. Claude Desktop connectors),
     * mapped for display.
     *
     * @return array<int, array{id: int, client_name: string, projects_label: string, created_at_diff: string}>
     */
    #[Computed]
    public function connections(): array
    {
        return McpClientGrant::query()
            ->where('user_id', Auth::id())
            ->with(['client:id,name', 'projects:projects.id,projects.short_name'])
            ->latest()
            ->get()
            ->map(static fn (McpClientGrant $grant): array => [
                'id' => $grant->id,
                'client_name' => $grant->client->name,
                'projects_label' => $grant->restrictsProjects()
                    ? $grant->projects->pluck('short_name')->sort()->implode(', ')
                    : __('All projects'),
                'created_at_diff' => $grant->created_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Whether the user may mint an audit-stream token — an instance operator
     * with the account-level manage-users permission. Drives the admin-only
     * access-level option and re-checked on submit.
     */
    #[Computed]
    public function canMintAuditTokens(): bool
    {
        return Auth::user()->can(Permission::ManageUsers->value);
    }

    /**
     * The display label for a token's granted abilities: the audit stream, the
     * read/write split otherwise.
     *
     * @param  array<int, string>  $abilities
     */
    private static function abilitiesLabel(array $abilities): string
    {
        return match (true) {
            in_array(TokenAbility::Audit->value, $abilities, true) => TokenAbility::Audit->label(),
            in_array(TokenAbility::Write->value, $abilities, true) => TokenAbility::Write->label(),
            default => TokenAbility::Read->label(),
        };
    }

    /**
     * The user's project memberships, offered as choices when restricting a
     * new token to selected projects.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Auth::user()->projects()
            ->orderBy('title')
            ->get(['projects.id', 'projects.short_name', 'projects.title']);
    }

    /**
     * Get the authenticated user's tokens mapped for display.
     *
     * @return array<int, array{id: int, name: string, abilities_label: string, projects_label: string, last_used_at_diff: string|null, created_at_diff: string}>
     */
    #[Computed]
    public function tokens(): array
    {
        return Auth::user()->tokens()
            ->select(['id', 'name', 'abilities', 'restricts_projects', 'last_used_at', 'created_at'])
            ->with('projects:projects.id,projects.short_name')
            ->latest()
            ->get()
            ->map(static fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities_label' => self::abilitiesLabel($token->abilities ?? []),
                'projects_label' => $token->restrictsProjects()
                    ? $token->projects->pluck('short_name')->sort()->implode(', ')
                    : __('All projects'),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                'created_at_diff' => $token->created_at->diffForHumans(),
            ])
            ->all();
    }
}
