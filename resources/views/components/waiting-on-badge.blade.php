@props(['task'])

{{-- The "waiting on somebody" marker: deliberately unlike the red lock of a
     blocked task — nothing is technically blocking the work, a person owes it an
     answer. Shows who is awaited and how long the wait has run, turning red once
     the wait passes the project's reminder threshold (the same line the nudge
     command uses), so an overdue request reads as overdue at a glance. --}}
@if ($task->waitingOn)
    @php($days = $task->waitingDays())
    @php($overdue = $task->isWaitOverdue())

    <flux:tooltip
        :content="$task->waiting_since !== null
            ? __('Waiting on :name since :since', ['name' => $task->waitingOn->name, 'since' => $task->waiting_since->diffForHumans()])
            : __('Waiting on :name', ['name' => $task->waitingOn->name])"
    >
        <flux:badge
            size="sm"
            :color="$overdue ? 'red' : 'amber'"
            icon="clock"
            :data-test="'waiting-on-'.$task->id"
            :data-overdue="$overdue ? 'true' : null"
        >
            {{ $task->waitingOn->name }}@if ($days !== null)
                <span class="ms-1 opacity-70">· {{ $days }}d</span>
            @endif
        </flux:badge>
    </flux:tooltip>
@endif
