/**
 * Hovercard for #references in rendered rich text — tasks and docs alike.
 *
 * Hovering a `<a class="reference">` link fetches a compact preview from its
 * `…/preview` endpoint and shows it in a floating card: for a task its title,
 * status, priority, assignees and subtask progress; for a doc whether it is a
 * draft, the opening of its body and how many docs are nested under it. The
 * preview respects access — the endpoint 403s/404s for items the reader can't see
 * or that no longer exist — so the card simply doesn't appear in those cases. The
 * link itself always works.
 */

import { escapeHtml, registerHovercard } from './hovercard';

/** The card body for a doc preview. */
function renderDoc(data) {
    const meta = [data.visibility];

    if (data.nested) {
        meta.push(data.nested);
    }

    const excerpt = data.excerpt
        ? `<div class="reference-hovercard-assignees">${escapeHtml(data.excerpt)}</div>`
        : '';

    return `
        <div class="reference-hovercard-ref">${escapeHtml(data.reference)}</div>
        <div class="reference-hovercard-title">${escapeHtml(data.title)}</div>
        <div class="reference-hovercard-meta">${meta.map(escapeHtml).join(' · ')}</div>
        ${excerpt}
    `;
}

/** The card body for a task preview. */
function renderTask(data) {
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
}

registerHovercard({
    selector: 'a.reference',
    className: 'reference-hovercard',
    endpoint: (anchor) => {
        const href = anchor.getAttribute('href');

        return href ? `${href}/preview` : null;
    },
    title: (data) => `${data.reference} · ${data.title}`,
    render: (data) => (data.type === 'doc' ? renderDoc(data) : renderTask(data)),
});
