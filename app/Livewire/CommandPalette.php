<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Support\GlobalSearch;
use App\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CommandPalette extends Component
{
    public string $query = '';

    /**
     * The short_name of the project the user is viewing when the palette mounts,
     * used to prioritize that project's tasks on bare-number searches.
     */
    public ?string $contextShortName = null;

    public function mount(): void
    {
        $shortName = request()->route('short_name');
        $this->contextShortName = is_string($shortName) ? $shortName : null;
    }

    /**
     * Entity matches (projects, tasks) for the current query.
     *
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function results(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        return app(GlobalSearch::class)->search($user, $this->query, $this->contextShortName);
    }

    /**
     * The palette entries in display order: entity matches, then quick actions,
     * with completed/canceled tasks sunk to the very bottom so they never sit
     * above the action a user is reaching for (KAN-327). The sort is stable, so
     * everything else keeps its order.
     *
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->results()
            ->merge($this->actions())
            ->sortBy(static fn (SearchResult $item): int => $item->deprioritized ? 1 : 0)
            ->values();
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

        if ($user->projects()->exists()) {
            $actions->push(new SearchResult(type: 'action', title: __('New task'), icon: 'plus', event: 'open-create-task'));
        }

        $actions->push(new SearchResult(type: 'action', title: __('New note'), icon: 'pencil-square', event: 'open-create-note'));

        if (($docProject = $this->docCreationProject($user)) !== null) {
            $actions->push(new SearchResult(
                type: 'action',
                title: __('New doc'),
                url: route('project.docs', ['short_name' => $docProject->short_name, 'create' => 1]),
                icon: 'document-text',
            ));
        }

        if ($user->hasPermission(Permission::CreateProjects)) {
            $actions->push(new SearchResult(type: 'action', title: __('New project'), url: route('projects.index', ['create' => 1]), icon: 'folder-plus'));
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
     * The project a "New doc" action would create the doc in: the project being
     * viewed when the palette was opened, or — off any project page — the user's
     * only one. A doc always belongs to a project, so with several projects and
     * no context there is nothing to preselect and the action is left out.
     */
    private function docCreationProject(User $user): ?Project
    {
        $projects = $user->projects()
            ->when(
                $this->contextShortName !== null,
                fn (Builder $query): Builder => $query->where('short_name', strtoupper($this->contextShortName)),
            )
            ->limit(2)
            ->get();

        $project = $projects->count() === 1 ? $projects->first() : null;

        return $project !== null && Gate::allows('create-doc', $project) ? $project : null;
    }

    /**
     * Navigate to the selected entry using SPA-style navigation.
     */
    public function go(string $url): void
    {
        $this->redirect($url, navigate: true);
    }

    /**
     * Run an action that opens an in-page dialog rather than navigating: dispatch
     * its event and close the palette.
     */
    public function runAction(string $event): void
    {
        $this->dispatch($event);
        $this->reset('query');
        $this->dispatch('modal-close', name: 'command-palette');
    }

    /**
     * Clear the query when the palette closes, so the next open starts on the
     * quick-actions view rather than a stale search.
     */
    public function close(): void
    {
        $this->reset('query');
    }
}
