<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
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
                    <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.index')" wire:navigate>
                        {{ __('Notifications') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if (auth()->user()->projects->isNotEmpty())
                    <flux:sidebar.group :heading="__('My projects')" class="grid min-w-0 grid-cols-1">
                        @foreach (auth()->user()->projects()->orderBy('title')->get() as $project)
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

            <flux:spacer />

            {{-- Persist the notifications menu across wire:navigate transitions so its
                 30s poll and unread state survive page changes instead of re-mounting
                 (and re-querying) on every navigation. --}}
            @persist('notifications-menu')
                <livewire:notifications.notifications-menu />
            @endpersist
        </flux:header>

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
