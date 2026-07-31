/**
 * Hovercard for [variable] usages in rendered rich text.
 *
 * A usage renders as `<span class="variable" data-variable="hero">Robin
 * Hood</span>` — value and all — so the card needs no request: it reads the name
 * off the element and the value off its text. An unset variable renders its name
 * instead and carries a translated `data-unset-label`, which is both the label to
 * show and how the card knows there is no value.
 *
 * The point of the card is to say *which* variable the prose is showing, since
 * the substituted text reads as ordinary prose by design.
 */

import { escapeHtml, registerHovercard } from './hovercard';

registerHovercard({
    selector: 'span.variable[data-variable]',
    className: 'variable-hovercard',
    data: (element) => {
        const unsetLabel = element.getAttribute('data-unset-label');

        return {
            name: element.getAttribute('data-variable'),
            value: unsetLabel === null ? element.textContent.trim() : null,
            unsetLabel: unsetLabel ?? '',
        };
    },
    title: (data) => `[${data.name}] · ${data.value ?? data.unsetLabel}`,
    render: (data) => `
        <span class="variable-hovercard-name">[${escapeHtml(data.name)}]</span>
        <span class="${data.value === null ? 'variable-hovercard-unset' : 'variable-hovercard-value'}">
            ${escapeHtml(data.value ?? data.unsetLabel)}
        </span>
    `,
});
