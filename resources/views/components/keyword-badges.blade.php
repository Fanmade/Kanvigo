@props(['keywords'])

@if ($keywords->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'flex flex-wrap gap-1']) }}>
        @foreach ($keywords as $keyword)
            <flux:badge size="sm" color="zinc" variant="pill" icon="tag">{{ $keyword->name }}</flux:badge>
        @endforeach
    </div>
@endif
