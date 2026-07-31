/**
 * A shared hover-preview popover.
 *
 * Powers the #task-reference, @mention and [variable] hovercards: given a
 * selector, a source of preview data — either a function that builds a JSON
 * endpoint to fetch, or one that reads the data straight off the hovered element
 * — an optional native-`title` builder (the accessible/no-card baseline), and a
 * renderer, it wires up debounced show/hide, per-URL caching and positioning.
 *
 * A fetched endpoint is expected to 403/404 for links the reader can't see (or
 * that no longer exist), in which case no card is shown — the link itself always
 * works.
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
 * @param {(anchor: Element) => (string|null)} [options.endpoint] - builds the JSON
 *     URL to fetch for a hovered anchor, or null to skip.
 * @param {(anchor: Element) => (object|null)} [options.data] - reads the preview
 *     data off the element itself, for cards that need no request. Takes
 *     precedence over `endpoint`.
 * @param {(data: object) => string} options.render - returns the card's innerHTML.
 * @param {(data: object) => string} [options.title] - optional native `title`.
 */
export function registerHovercard({ selector, className, endpoint = null, data: readData = null, render, title = null }) {
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

    /** The preview for a hovered element — read off it, or fetched. */
    async function preview(anchor) {
        if (readData) {
            return readData(anchor);
        }

        const url = endpoint?.(anchor);

        return url ? fetchPreview(url) : null;
    }

    async function show(anchor) {
        const data = await preview(anchor);

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
