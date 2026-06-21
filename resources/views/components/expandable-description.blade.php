@props(['content'])

{{-- A rich-text (HTML) description capped to a default height (scrolling within
     its card so it never grows the page), with a Show more / Show less toggle to
     expand to full height and collapse again. The toggle only appears when the
     content actually overflows the cap. --}}
<div
    x-data="{
        expanded: false,
        overflowing: false,
        init() {
            this.$nextTick(() => {
                this.overflowing = this.$refs.body.scrollHeight > this.$refs.body.clientHeight;
            });
        },
    }"
>
    <div
        x-ref="body"
        :class="expanded ? 'max-h-none' : 'max-h-96 overflow-y-auto'"
    >
        <x-rich-text :content="$content" />
    </div>

    <button
        type="button"
        x-show="overflowing"
        x-cloak
        x-on:click="expanded = ! expanded"
        class="mt-1 text-sm font-medium text-accent hover:underline"
        data-test="toggle-description"
        x-text="expanded ? @js(__('Show less')) : @js(__('Show more'))"
    ></button>
</div>
