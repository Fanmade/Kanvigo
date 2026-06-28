/**
 * Hovercard for #task references in rendered rich text.
 *
 * Hovering a `<a class="reference">` link fetches a compact preview of the task
 * (title, status, priority, assignees, subtask progress) from its `…/preview`
 * endpoint and shows it in a floating card. The preview respects access — the
 * endpoint 403s/404s for tasks the reader can't see or that no longer exist — so
 * the card simply doesn't appear in those cases. The link itself always works.
 */

import { escapeHtml, registerHovercard } from './hovercard';

registerHovercard({
    selector: 'a.reference',
    className: 'reference-hovercard',
    endpoint: (anchor) => {
        const href = anchor.getAttribute('href');

        return href ? `${href}/preview` : null;
    },
    title: (data) => `${data.reference} · ${data.title}`,
    render: (data) => {
        const meta = [data.status, data.priority];

        if (data.progress) {
            meta.push(data.progress.label);
        }

        const blocked = data.is_blocked
            ? '<span class="reference-hovercard-blocked">Blocked</span>'
            : '';

        const assignees = data.assignees.length
            ? `<div class="reference-hovercard-assignees">${data.assignees.map(escapeHtml).join(', ')}</div>`
            : '';

        return `
            <div class="reference-hovercard-ref">${escapeHtml(data.reference)}${blocked}</div>
            <div class="reference-hovercard-title">${escapeHtml(data.title)}</div>
            <div class="reference-hovercard-meta">${meta.map(escapeHtml).join(' · ')}</div>
            ${assignees}
        `;
    },
});
