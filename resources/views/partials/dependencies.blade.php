@php($canManage = $this->canManageDependencies)

<div class="flex flex-col gap-3" data-test="dependencies" @if ($canManage) x-data="{ adding: @js($errors->has('dependencyReference')) }" @endif>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="sm" data-test="relationships-heading">{{ __('Relationships') }}</flux:heading>
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

    @forelse ($this->relationshipGroups as $group)
        <div class="flex flex-col gap-1.5" wire:key="rel-group-{{ $group['keyword'] }}">
            <flux:text size="xs" class="font-medium text-zinc-400">{{ $group['heading'] }}</flux:text>
            @foreach ($group['links'] as $entry)
                <x-dependency-item :item="$entry['related']" :link-id="$entry['link']->id" :can-remove="$canManage" />
            @endforeach
        </div>
    @empty
        <flux:text size="sm" class="text-zinc-400">{{ __('No relationships yet.') }}</flux:text>
    @endforelse

    @if ($canManage)
        <form wire:submit="addDependency" class="flex flex-col gap-2" x-show="adding" x-cloak>
            <flux:select wire:model="dependencyDirection" :label="__('Relationship')" size="sm">
                @foreach ($this->relationshipOptions as $keyword => $label)
                    <flux:select.option value="{{ $keyword }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                wire:model.live.debounce.300ms="dependencyReference"
                :label="__('Reference')"
                :placeholder="__('Search by reference or title')"
                size="sm"
                data-test="dependency-reference"
            />

            @if ($this->dependencyCandidates->isNotEmpty())
                <div class="flex max-h-48 flex-col gap-0.5 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-white/10" data-test="dependency-candidates">
                    @foreach ($this->dependencyCandidates as $candidate)
                        <flux:button
                            type="button"
                            size="xs"
                            variant="ghost"
                            class="justify-start!"
                            wire:click="$set('dependencyReference', @js($candidate['reference']))"
                            wire:key="dep-option-{{ $candidate['reference'] }}"
                            :data-test="'dependency-candidate-'.$candidate['reference']"
                        >
                            <span class="truncate">{{ $candidate['label'] }}</span>
                        </flux:button>
                    @endforeach
                </div>
            @endif
            <flux:error name="dependencyReference" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" x-on:click="adding = false">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" size="sm" variant="primary" icon="plus" data-test="add-dependency">{{ __('Add') }}</flux:button>
            </div>
        </form>
    @endif
</div>
