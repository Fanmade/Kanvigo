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
  <path d="M13.054 18.946a11 11 0 0 1-2.11 0" />
  <path d="M13.054 5.054a11 11 0 0 0-2.11-.001" />
  <path d="M17.072 6.274a11 11 0 0 1 1.753 1.173" />
  <path d="M18.825 16.552a11 11 0 0 1-1.753 1.174" />
  <path d="M2.514 13.303a11 11 0 0 1-.452-.954 1 1 0 0 1 0-.697 11 11 0 0 1 .45-.955" />
  <path d="M21.485 10.697a11 11 0 0 1 .453.955 1 1 0 0 1 0 .697 11 11 0 0 1-.453.954" />
  <path d="M5.173 7.448a11 11 0 0 1 1.753-1.174" />
  <path d="M6.926 17.726a11 11 0 0 1-1.753-1.174" />
  <circle cx="12" cy="12" r="3" />
</svg>
