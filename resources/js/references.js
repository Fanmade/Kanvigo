/**
 * Hovercard for #task references in rendered rich text.
 *
 * Hovering a `<a class="reference">` link fetches a compact preview of the task
 * (title, status, priority, assignees, subtask progress) from its `…/preview`
 * endpoint and shows it in a floating card. The preview respects access — the
 * endpoint 403s/404s for tasks the reader can't see or that no longer exist — so
 * the card simply doesn't appear in those cases. The link itself always works.
 */

const SHOW_DELAY = 300;
const HIDE_DELAY = 150;

const cache = new Map();

let card = null;
let showTimer = null;
let hideTimer = null;
let current = null;

const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);

function ensureCard() {
    if (!card) {
        card = document.createElement('div');
        card.className = 'reference-hovercard';
        card.style.display = 'none';
        card.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        card.addEventListener('mouseleave', scheduleHide);
        document.body.appendChild(card);
    }

    return card;
}

/** Fetch (and cache) the preview for a reference link's href. */
function fetchPreview(href) {
    if (!cache.has(href)) {
        cache.set(
            href,
            fetch(`${href}/preview`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : null))
                .catch(() => null),
        );
    }

    return cache.get(href);
}

function renderCard(data) {
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

function position(anchor) {
    const rect = anchor.getBoundingClientRect();

    card.style.left = `${rect.left}px`;
    card.style.top = `${rect.bottom + 6}px`;
}

function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        if (card) {
            card.style.display = 'none';
        }
        current = null;
    }, HIDE_DELAY);
}

async function show(anchor) {
    const href = anchor.getAttribute('href');

    if (!href) {
        return;
    }

    const data = await fetchPreview(href);

    if (!data) {
        return;
    }

    // A native tooltip as the no-card baseline (and for accessibility).
    anchor.setAttribute('title', `${data.reference} · ${data.title}`);

    // Bail if the pointer has since moved off this reference.
    if (current !== anchor) {
        return;
    }

    ensureCard();
    card.innerHTML = renderCard(data);
    card.style.display = 'block';
    position(anchor);
}

document.addEventListener('mouseover', (event) => {
    const anchor = event.target.closest?.('a.reference');

    if (!anchor) {
        return;
    }

    current = anchor;
    clearTimeout(hideTimer);
    clearTimeout(showTimer);
    showTimer = setTimeout(() => show(anchor), SHOW_DELAY);
});

document.addEventListener('mouseout', (event) => {
    const anchor = event.target.closest?.('a.reference');

    if (!anchor) {
        return;
    }

    clearTimeout(showTimer);

    // Keep the card open if the pointer is moving into it.
    if (event.relatedTarget && card?.contains(event.relatedTarget)) {
        return;
    }

    scheduleHide();
});
