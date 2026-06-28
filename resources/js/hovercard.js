/**
 * A shared hover-preview popover.
 *
 * Powers both the #task-reference hovercard and the @mention hovercard: given a
 * link selector, a function that builds the JSON preview endpoint for a hovered
 * anchor, an optional native-`title` builder (the accessible/no-card baseline),
 * and a renderer for the fetched data, it wires up debounced show/hide, per-URL
 * caching and positioning.
 *
 * The endpoint is expected to 403/404 for links the reader can't see (or that no
 * longer exist), in which case no card is shown — the link itself always works.
 */

const SHOW_DELAY = 300;
const HIDE_DELAY = 150;

export const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);

/**
 * Register a hovercard for every anchor matching `selector`.
 *
 * @param {object} options
 * @param {string} options.selector - CSS selector for the hover targets.
 * @param {string} options.className - class applied to the floating card element.
 * @param {(anchor: Element) => (string|null)} options.endpoint - builds the JSON
 *     URL to fetch for a hovered anchor, or null to skip.
 * @param {(data: object) => string} options.render - returns the card's innerHTML.
 * @param {(data: object) => string} [options.title] - optional native `title`.
 */
export function registerHovercard({ selector, className, endpoint, render, title = null }) {
    const cache = new Map();

    let card = null;
    let showTimer = null;
    let hideTimer = null;
    let current = null;

    function ensureCard() {
        if (!card) {
            card = document.createElement('div');
            card.className = className;
            card.style.display = 'none';
            card.addEventListener('mouseenter', () => clearTimeout(hideTimer));
            card.addEventListener('mouseleave', scheduleHide);
            document.body.appendChild(card);
        }

        return card;
    }

    /** Fetch (and cache) the preview for a given URL. */
    function fetchPreview(url) {
        if (!cache.has(url)) {
            cache.set(
                url,
                fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((response) => (response.ok ? response.json() : null))
                    .catch(() => null),
            );
        }

        return cache.get(url);
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
        const url = endpoint(anchor);

        if (!url) {
            return;
        }

        const data = await fetchPreview(url);

        if (!data) {
            return;
        }

        // A native tooltip as the no-card baseline (and for accessibility).
        if (title) {
            anchor.setAttribute('title', title(data));
        }

        // Bail if the pointer has since moved off this anchor.
        if (current !== anchor) {
            return;
        }

        ensureCard();
        card.innerHTML = render(data);
        card.style.display = 'block';
        position(anchor);
    }

    document.addEventListener('mouseover', (event) => {
        const anchor = event.target.closest?.(selector);

        if (!anchor) {
            return;
        }

        current = anchor;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        showTimer = setTimeout(() => show(anchor), SHOW_DELAY);
    });

    document.addEventListener('mouseout', (event) => {
        const anchor = event.target.closest?.(selector);

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
}
