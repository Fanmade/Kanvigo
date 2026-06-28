/**
 * Hovercard for @user mentions in rendered rich text.
 *
 * Hovering an `<a class="mention">` link fetches a compact preview of the user
 * (name, avatar and — when the mention carries the project it lives in via
 * `data-project` — their role in that project) from the user's `…/preview`
 * endpoint and shows it in a floating card. Access is respected: the endpoint
 * 403s/404s for users the reader can't see or that no longer exist, so the card
 * simply doesn't appear. The profile link itself always works.
 *
 * Built to extend: as more user information becomes available, add fields to the
 * `/preview` endpoint and render them here without touching the mechanism.
 */

import { escapeHtml, registerHovercard } from './hovercard';

registerHovercard({
    selector: 'a.mention',
    className: 'mention-hovercard',
    endpoint: (anchor) => {
        const href = anchor.getAttribute('href');

        if (!href) {
            return null;
        }

        const project = anchor.getAttribute('data-project');

        return project
            ? `${href}/preview?project=${encodeURIComponent(project)}`
            : `${href}/preview`;
    },
    title: (data) =>
        data.roles?.length ? `${data.name} · ${data.roles.join(', ')}` : data.name,
    render: (data) => {
        const avatar = data.avatar_url
            ? `<img class="mention-hovercard-avatar" src="${escapeHtml(data.avatar_url)}" alt="">`
            : `<span class="mention-hovercard-initials">${escapeHtml(data.initials)}</span>`;

        const roles = data.roles?.length
            ? `<div class="mention-hovercard-roles">${data.roles
                  .map((role) => `<span class="mention-hovercard-role">${escapeHtml(role)}</span>`)
                  .join('')}</div>`
            : '';

        return `
            <div class="mention-hovercard-header">
                ${avatar}
                <div class="mention-hovercard-body">
                    <span class="mention-hovercard-name">${escapeHtml(data.name)}</span>
                    ${roles}
                </div>
            </div>
        `;
    },
});
