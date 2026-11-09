@blaze(fold: true)

{{-- Credit: Lucide (https://lucide.dev) --}}

@props([
    'variant' => 'outline',
])

@php
if ($variant === 'solid') {
    throw new \Exception('The "solid" variant is not supported in Lucide.');
}

$classes = Flux::classes('shrink-0')
    ->add(match($variant) {
        'outline' => '[:where(&)]:size-6',
        'solid' => '[:where(&)]:size-6',
        'mini' => '[:where(&)]:size-5',
        'micro' => '[:where(&)]:size-4',
    });

$strokeWidth = match ($variant) {
    'outline' => 2,
    'mini' => 2.25,
    'micro' => 2.5,
};
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="{{ $strokeWidth }}"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    data-slot="icon"
>
  <path d="m12.828 4.813 5.344 2.375" />
  <path d="m15.769 18.153 3.461-8.306" />
  <path d="m5.687 14.074 7.625 4.852" />
  <path d="M9.772 5.579 5.228 11.42" />
  <circle cx="11" cy="4" r="2" />
  <circle cx="15" cy="20" r="2" />
  <circle cx="20" cy="8" r="2" />
  <circle cx="4" cy="13" r="2" />
</svg>
