<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="rectangle-stack" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>
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
                    <flux:sidebar.group :heading="__('My projects')" class="grid">
                        @foreach (auth()->user()->projects()->orderBy('title')->get() as $project)
                            <flux:sidebar.item
                                icon="folder"
                                :href="route('project.show', $project)"
                                :current="request()->fullUrlIs(route('project.show', $project))"
                                wire:navigate
                            >
                                {{ $project->short_name }} · {{ $project->title }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @can('invite-users')
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="user-plus" :href="route('invitations.create')" :current="request()->routeIs('invitations.create')" wire:navigate>
                        {{ __('Invite user') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endcan
        </flux:sidebar>

        <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <livewire:notifications.notifications-menu />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
