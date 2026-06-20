@props([
    'status',
    'model' => 'status',
    'canEdit' => false,
    'size' => 'sm',
])

@if ($canEdit)
    <flux:dropdown align="end" data-test="status-control">
        <flux:badge
            as="button"
            :size="$size"
            :color="$status->color()"
            :icon="$status->icon()"
            icon:trailing="chevron-down"
            class="cursor-pointer"
        >
            {{ $status->label() }}
        </flux:badge>

        <flux:menu>
            <flux:menu.radio.group wire:model.live="{{ $model }}">
                @foreach (\App\Enums\Status::columns() as $option)
                    <flux:menu.radio :value="$option->value" :icon="$option->icon()" data-test="status-option-{{ $option->value }}">
                        {{ $option->label() }}
                    </flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>
@else
    <flux:badge :size="$size" :color="$status->color()" :icon="$status->icon()" data-test="status-control">
        {{ $status->label() }}
    </flux:badge>
@endif
