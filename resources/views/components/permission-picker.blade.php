@props(['groups', 'model', 'testPrefix', 'resolver', 'emptyMessage' => null])

{{--
    The grouped permission checkbox grid used by the role create and edit forms. The
    chosen permission ids are bound to the Livewire property $model; $resolver is the
    Livewire component, used to resolve each permission's label and help text.

    - groups:       group name => permissions to list under it.
    - model:        the Livewire property the checkboxes bind to.
    - testPrefix:   data-test prefix ("{testPrefix}-{name}", "{testPrefix}-hint-{name}").
    - resolver:     the component exposing permissionPickerLabel()/permissionDescription().
    - emptyMessage: shown when there are no groups (e.g. no parent role chosen yet).
--}}
<flux:checkbox.group
    wire:model="{{ $model }}"
    {{ $attributes->merge(['class' => 'columns-1 gap-x-8 sm:columns-2 lg:columns-3']) }}
>
    @forelse ($groups as $group => $permissions)
        <div
            class="mb-3 flex break-inside-avoid flex-col gap-1"
            wire:key="{{ $testPrefix }}-group-{{ \Illuminate\Support\Str::slug($group) }}"
        >
            <flux:text size="xs" class="font-medium text-zinc-400">{{ $group }}</flux:text>
            <div class="flex flex-col gap-1">
                @foreach ($permissions as $permission)
                    <div class="flex items-center gap-1.5">
                        <flux:checkbox
                            value="{{ $permission->id }}"
                            :label="$resolver->permissionPickerLabel($permission->name)"
                            data-test="{{ $testPrefix }}-{{ $permission->name }}"
                        />
                        @if ($description = $resolver->permissionDescription($permission->name))
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
