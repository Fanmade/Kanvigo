@props(['date'])

{{--
    A relative timestamp ("5 hours ago") that reveals the absolute date and time
    on hover. Used wherever a humanised time is shown (activity log, comments,
    notifications, task metadata). Renders nothing for a null date, mirroring the
    `$date?->diffForHumans()` it replaces.
--}}
@if ($date)
    @php($carbon = $date instanceof \Carbon\CarbonInterface ? $date : \Illuminate\Support\Carbon::parse($date))
    <flux:tooltip :content="$carbon->isoFormat('LLL')">
        <span {{ $attributes->class('cursor-help') }}>{{ $carbon->diffForHumans() }}</span>
    </flux:tooltip>
@endif
