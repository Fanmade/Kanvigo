@props(['groups', 'model', 'testPrefix', 'resolver', 'allowed' => null, 'emptyMessage' => null])

{{--
    The grouped permission checkbox grid used by the role detail pane's create and
    edit forms. The chosen permission ids are bound to the Livewire property $model;
    $resolver is the Livewire component, used to resolve each permission's label and
    help text.

    - groups:       group name => permissions to list under it.
    - model:        the Livewire property the checkboxes bind to.
    - testPrefix:   data-test prefix ("{testPrefix}-{name}", "{testPrefix}-hint-{name}").
    - resolver:     the component exposing permissionPickerLabel()/permissionDescription().
    - allowed:      permission names the parent role holds; anything outside is shown
                    disabled with a hint. Null allows everything.
    - emptyMessage: shown when there are no groups.
--}}
<flux:checkbox.group
    wire:model="{{ $model }}"
    {{ $attributes->merge(['class' => 'grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3']) }}
>
    @forelse ($groups as $group => $permissions)
        <div
            class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-white/10"
            wire:key="{{ $testPrefix }}-group-{{ \Illuminate\Support\Str::slug($group) }}"
        >
            <flux:text
                size="xs"
                class="font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400"
            >{{ __($group) }}</flux:text>
            <div class="flex flex-col gap-1.5">
                @foreach ($permissions as $permission)
                    @php($outOfBounds = ($allowed !== null && ! in_array($permission->name, $allowed, true)))
                    <div class="flex items-center gap-1.5">
                        <flux:checkbox
                            value="{{ $permission->id }}"
                            :label="$resolver->permissionPickerLabel($permission->name)"
                            :disabled="$outOfBounds"
                            data-test="{{ $testPrefix }}-{{ $permission->name }}"
                        />
                        @if ($outOfBounds)
                            <flux:tooltip
                                :content="__('The parent role does not hold this permission, so it cannot be delegated.')">
                                <flux:icon.lock-closed
                                    variant="micro"
                                    class="cursor-help text-zinc-400"
                                    tabindex="0"
                                    data-test="{{ $testPrefix }}-bound-{{ $permission->name }}"
                                />
                            </flux:tooltip>
                        @elseif ($description = $resolver->permissionDescription($permission->name))
                            <flux:tooltip :content="$description">
                                <flux:icon.question-mark-circle
                                    variant="micro"
                                    class="cursor-help text-zinc-400"
                                    tabindex="0"
                                    data-test="{{ $testPrefix }}-hint-{{ $permission->name }}"
                                />
                            </flux:tooltip>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        @if ($emptyMessage)
            <flux:text size="sm" class="text-zinc-400">{{ $emptyMessage }}</flux:text>
        @endif
    @endforelse
</flux:checkbox.group>
