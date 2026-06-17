<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Models\User;
use App\Support\GlobalSearch;
use App\Support\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CommandPalette extends Component
{
    public string $query = '';

    /**
     * Whether the palette is open. Gates rendering of the result list so a closed
     * palette never leaves its (hidden) items in the DOM, where they would shadow
     * matching page elements for accessibility tools and text-based selectors.
     */
    public bool $open = false;

    /**
     * Entity matches (projects, stories, tasks) for the current query.
     *
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function results(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        return app(GlobalSearch::class)->search($user, $this->query);
    }

    /**
     * Quick actions, gated by permission and filtered by the current query.
     *
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function actions(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var Collection<int, SearchResult> $actions */
        $actions = collect([
            new SearchResult(type: 'action', title: __('Dashboard'), url: route('dashboard'), icon: 'home'),
            new SearchResult(type: 'action', title: __('Projects'), url: route('projects.index'), icon: 'rectangle-stack'),
            new SearchResult(type: 'action', title: __('Board'), url: route('board'), icon: 'view-columns'),
            new SearchResult(type: 'action', title: __('Notifications'), url: route('notifications.index'), icon: 'bell'),
        ]);

        if ($user->hasPermission(Permission::CreateProjects)) {
            $actions->push(new SearchResult(type: 'action', title: __('New project'), url: route('projects.index'), icon: 'folder-plus'));
        }

        if ($user->hasPermission(Permission::InviteUsers)) {
            $actions->push(new SearchResult(type: 'action', title: __('Invite user'), url: route('invitations.create'), icon: 'user-plus'));
        }

        $query = trim($this->query);

        if ($query === '') {
            return $actions->values();
        }

        return $actions
            ->filter(static fn (SearchResult $action): bool => str_contains(mb_strtolower($action->title), mb_strtolower($query)))
            ->values();
    }

    /**
     * Navigate to the selected entry using SPA-style navigation.
     */
    public function go(string $url): void
    {
        $this->redirect($url, navigate: true);
    }

    /**
     * Reset the palette when it closes so the next open starts empty.
     */
    public function close(): void
    {
        $this->reset('open', 'query');
    }
}
