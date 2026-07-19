<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark', 'full-width' => auth()->user()?->preference('full_width', false)])>
    <!--suppress HtmlRequiredTitleElement -->
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="rectangle-stack" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate data-test="nav-projects">
                        {{ __('Projects') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="view-columns" :href="route('board')" :current="request()->routeIs('board')" wire:navigate>
                        {{ __('Board') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('notes.index')" :current="request()->routeIs('notes.index')" wire:navigate data-test="nav-notes">
                        {{ __('Notes') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.index')" wire:navigate>
                        {{ __('Notifications') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @php($sidebarProjects = auth()->user()->projects->sortBy('title'))
                @if ($sidebarProjects->isNotEmpty())
                    <flux:sidebar.group :heading="__('My projects')" class="grid min-w-0 grid-cols-1">
                        @foreach ($sidebarProjects as $project)
                            <flux:sidebar.item
                                icon="folder"
                                :href="route('project.board', $project)"
                                :current="request()->fullUrlIs(route('project.board', $project))"
                                wire:navigate
                                :title="$project->short_name.' · '.$project->title"
                                data-test="nav-project-{{ $project->id }}"
                            >
                                {{ $project->short_name }} · {{ $project->title }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @canany(['invite-users', 'manage-users'])
                <flux:sidebar.nav>
                    @can('invite-users')
                        <flux:sidebar.item icon="user-plus" :href="route('invitations.create')" :current="request()->routeIs('invitations.create')" wire:navigate>
                            {{ __('Invite user') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('manage-users')
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate data-test="nav-user-administration">
                            {{ __('User administration') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.nav>
            @endcanany

            <x-sidebar-account-menu />
        </flux:sidebar>

        <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <livewire:command-palette />

            {{-- On mobile the search bar grows to fill the header, so the spacer
                 would compete with it for the slack — only push from `sm` up. --}}
            <flux:spacer class="max-sm:hidden" />

            {{-- Global shortcut to the app's most-used action, sitting to the left of
                 the account avatar. Fires the same no-payload open-create-task event
                 as the command palette, so the create-task modal infers the project
                 from the current route, auto-selects a sole project, or falls back to
                 its project picker. Dispatched via Livewire.dispatch (not wire:click)
                 because the header layout is not itself a Livewire component. Only
                 shown when the user has a project to add a task to, mirroring the
                 command palette's gate. The label collapses to an icon on mobile,
                 where the search bar claims the header width. --}}
            @if (auth()->user()->projects()->exists())
                <flux:button
                    variant="primary"
                    icon="plus"
                    size="sm"
                    class="me-3"
                    x-on:click="Livewire.dispatch('open-create-task')"
                    :aria-label="__('New task')"
                    data-test="toolbar-new-task"
                >
                    <span class="max-sm:hidden">{{ __('New task') }}</span>
                </flux:button>
            @endif

            {{-- Persist the notifications menu across wire:navigate transitions so its
                 30s poll and unread state survive page changes instead of re-mounting
                 (and re-querying) on every navigation. --}}
            @persist('notifications-menu')
                <livewire:notifications.notifications-menu />
            @endpersist
        </flux:header>

        {{-- Flux persists the desktop collapse state in localStorage and only
             applies it once @fluxScripts (loaded at the end of the body) upgrades
             the sidebar — so a collapsed sidebar would render expanded and visibly
             snap shut on every load. Apply the collapsed state here, before first
             paint, to remove that flash (KAN-291). Keep this AFTER the header, not
             between the sidebar and header: Flux's full-height-sidebar grid keys off
             the `[data-flux-sidebar]+[data-flux-header]` adjacent-sibling selector, so
             any node wedged between them drops the header to a full-width bar above
             the sidebar (KAN-330). --}}
        <script>
            (function () {
                try {
                    if (window.matchMedia('(min-width: 1024px)').matches
                        && JSON.parse(localStorage.getItem('flux-sidebar-collapsed-desktop'))) {
                        document.querySelector('[data-flux-sidebar]')
                            ?.setAttribute('data-flux-sidebar-collapsed-desktop', '');
                    }
                } catch (error) {
                    // No stored preference (or storage unavailable) — render expanded.
                }
            })();
        </script>

        {{ $slot }}

        <livewire:tasks.create-task-modal />

        <livewire:notes.create-note-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
