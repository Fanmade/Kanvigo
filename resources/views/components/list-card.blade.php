{{--
    A bordered card holding a vertical, divided list of rows (the dashboard task and
    note lists, the notes list, a task's subtasks). Rows go in the default slot and
    are separated by hairlines; extra attributes (e.g. data-test) pass through.
--}}
<flux:card {{ $attributes->merge(['class' => 'flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700']) }}>
    {{ $slot }}
</flux:card>
