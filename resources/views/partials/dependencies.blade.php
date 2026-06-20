@php($canManage = $this->canManageDependencies)

<div class="flex flex-col gap-3" data-test="dependencies" @if ($canManage) x-data="{ adding: @js($errors->has('dependencyReference')) }" @endif>
    <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <flux:heading size="sm">{{ __('Dependencies') }}</flux:heading>
            @if ($this->isBlocked)
                <flux:badge size="sm" color="red" icon="lock-closed" data-test="blocked-badge">{{ __('Blocked') }}</flux:badge>
            @endif
        </div>
        @if ($canManage)
            <flux:button size="xs" variant="subtle" icon="plus" x-on:click="adding = ! adding" data-test="toggle-add-dependency">
                {{ __('Add') }}
            </flux:button>
        @endif
    </div>

    @if ($this->presentBlockerLinks->isNotEmpty())
        <div class="flex flex-col gap-1.5">
            <flux:text size="xs" class="font-medium text-zinc-400">{{ __('Blocked by') }}</flux:text>
            @foreach ($this->presentBlockerLinks as $link)
                <x-dependency-item :item="$link->blocker" :link-id="$link->id" :can-remove="$canManage" />
            @endforeach
        </div>
    @endif

    @if ($this->presentBlockingLinks->isNotEmpty())
        <div class="flex flex-col gap-1.5">
            <flux:text size="xs" class="font-medium text-zinc-400">{{ __('Blocks') }}</flux:text>
            @foreach ($this->presentBlockingLinks as $link)
                <x-dependency-item :item="$link->dependent" :link-id="$link->id" :can-remove="$canManage" />
            @endforeach
        </div>
    @endif

    @if ($this->presentBlockerLinks->isEmpty() && $this->presentBlockingLinks->isEmpty())
        <flux:text size="sm" class="text-zinc-400">{{ __('No blockers or links yet.') }}</flux:text>
    @endif

    @if ($canManage)
        <form wire:submit="addDependency" class="flex flex-col gap-2" x-show="adding" x-cloak>
            <flux:select wire:model="dependencyDirection" :label="__('Relationship')" size="sm">
                <flux:select.option value="blocked_by">{{ __('Blocked by') }}</flux:select.option>
                <flux:select.option value="blocks">{{ __('Blocks') }}</flux:select.option>
            </flux:select>
            <flux:select
                variant="combobox"
                wire:model="dependencyReference"
                :label="__('Reference')"
                :placeholder="__('Search by reference or title')"
                size="sm"
                data-test="dependency-reference"
            >
                @foreach ($this->dependencyCandidates as $candidate)
                    <flux:select.option :value="$candidate['reference']" wire:key="dep-option-{{ $candidate['reference'] }}">
                        {{ $candidate['label'] }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="dependencyReference" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" x-on:click="adding = false">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" size="sm" variant="primary" icon="plus" data-test="add-dependency">{{ __('Add') }}</flux:button>
            </div>
        </form>
    @endif
</div>
