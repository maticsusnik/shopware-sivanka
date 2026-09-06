import Plugin from 'src/plugin-system/plugin.class';

/**
 * Home rails — categories, "Pravkar prišlo", "Najbolj priljubljeno".
 *
 * The reference draws each one as a native horizontal scroller: a flex track of
 * fixed-width cards inside an overflow container. Card widths are therefore plain
 * CSS and land on the design's 380 / 480 / 720 steps exactly.
 *
 * Shopware's tiny-slider wrapper cannot do that: `SliderSettingsHelper` resolves the
 * `responsive` map against the six Bootstrap viewport names only (0 / 576 / 768 /
 * 992 / 1200 / 1400), so a 720px step is unreachable and `items: N` stretches the
 * card to fill the container — 198px instead of 259px at 1100.
 *
 * On top of the scroller this adds:
 *   - an endless loop, by MOVING the edge card to the other end rather than cloning
 *     it. Clones would duplicate every wishlist button and buy form in the rail and
 *     leave them unbound; recycling the real node keeps every binding intact.
 *   - mouse dragging, with the click that follows a drag suppressed.
 *   - the arrow pair, animated on rAF rather than `behavior: 'smooth'`, so the loop
 *     can rewrite `scrollLeft` mid-animation without cancelling it.
 */
export default class SvRailPlugin extends Plugin {
    static options = {
        trackSelector: '.sv-rail__track',
        prevSelector: '.sv-rail__prev',
        nextSelector: '.sv-rail__next',
        /** Pointer travel, in px, past which the following click is a drag, not a tap. */
        dragThreshold: 6,
    };

    init() {
        this.track = this.el.querySelector(this.options.trackSelector);
        this.prev = this.el.querySelector(this.options.prevSelector);
        this.next = this.el.querySelector(this.options.nextSelector);

        if (!this.track || !this.track.children.length) {
            return;
        }

        this.dragging = false;
        this.dragged = false;
        this.raf = null;

        this._measure();
        this._registerEvents();
        this._update();
    }

    /**
     * Looping needs enough runway that moving the first card to the end always frees
     * some: with less content than that the rail is a plain, finite scroller.
     */
    _measure() {
        const overflow = this.track.scrollWidth - this.track.clientWidth;
        const widest = Array.from(this.track.children)
            .reduce((max, el) => Math.max(max, el.getBoundingClientRect().width), 0);

        this.loops = overflow > 1 && overflow >= widest;
        this.el.classList.toggle('sv-rail--static', overflow <= 1);
        this.el.classList.toggle('sv-rail--loops', this.loops);
    }

    _registerEvents() {
        this.prev?.addEventListener('click', () => this._page(-1));
        this.next?.addEventListener('click', () => this._page(1));

        this.track.addEventListener('scroll', () => {
            this._recycle();
            this._update();
        }, { passive: true });

        window.addEventListener('resize', () => {
            this._measure();
            this._update();
        }, { passive: true });

        this._registerDrag();
    }

    // ---- dragging ---------------------------------------------------------

    _registerDrag() {
        this.track.addEventListener('pointerdown', (event) => {
            // Touch and pen keep the browser's own scrolling, which is smoother than
            // anything we can do here and keeps scroll-snap working.
            if (event.pointerType !== 'mouse' || event.button !== 0) {
                return;
            }

            this._stop();
            this.dragging = true;
            this.dragged = false;
            this.startX = event.clientX;
            this.lastX = event.clientX;
            // Cursor only. `is--dragging` also takes the links out of the hit test, and
            // adding it here would mean mousedown and mouseup land on different targets
            // — the browser then fires no click at all and a plain click stops working.
            this.el.classList.add('is--pressing');
        });

        this.track.addEventListener('pointermove', (event) => {
            if (!this.dragging) {
                return;
            }

            if (!this.dragged
                && Math.abs(event.clientX - this.startX) > this.options.dragThreshold) {
                this.dragged = true;
                // Claim the pointer only once it is a drag, so a plain click on a card
                // still reaches the link.
                this.track.setPointerCapture(event.pointerId);
                this.el.classList.add('is--dragging');
            }

            if (this.dragged) {
                event.preventDefault();

                // Frame-to-frame delta, not `startScroll - totalDx`. An absolute anchor
                // goes stale the moment `_recycle()` shifts `scrollLeft` by a card width:
                // the next move undoes the shift, which triggers another recycle, and the
                // rail tears through the whole list in a few frames.
                const dx = event.clientX - this.lastX;
                this._scrollTo(this.track.scrollLeft - dx);
            }

            this.lastX = event.clientX;
        });

        const end = (event) => {
            if (!this.dragging) {
                return;
            }

            const wasDrag = this.dragged;

            this.dragging = false;
            this.el.classList.remove('is--pressing');
            this.el.classList.remove('is--dragging');

            if (this.track.hasPointerCapture?.(event.pointerId)) {
                this.track.releasePointerCapture(event.pointerId);
            }

            if (wasDrag) {
                this._settle();
            }
        };

        this.track.addEventListener('pointerup', end);
        this.track.addEventListener('pointercancel', end);

        // Swallow the click that ends a drag — otherwise letting go over a card
        // navigates to it.
        this.track.addEventListener('click', (event) => {
            if (this.dragged) {
                event.preventDefault();
                event.stopPropagation();
                this.dragged = false;
            }
        }, true);

        this.track.addEventListener('dragstart', (event) => event.preventDefault());
    }

    // ---- looping ----------------------------------------------------------

    /** The gap between two cards, read off the layout rather than hard-coded. */
    _gap() {
        const [first, second] = this.track.children;

        if (!first || !second) {
            return 0;
        }

        return second.getBoundingClientRect().left
            - first.getBoundingClientRect().right;
    }

    /** One card plus the gap after it. */
    _step() {
        const first = this.track.firstElementChild;

        return first
            ? first.getBoundingClientRect().width + this._gap()
            : this.track.clientWidth;
    }

    /**
     * Keep the scroll position inside the track by moving cards between its ends.
     * The card is the same node either way, so its wishlist button and buy form stay
     * bound.
     */
    _recycle() {
        if (!this.loops || this.recycling) {
            return;
        }

        this.recycling = true;
        const gap = this._gap();

        // Guarded: a mis-measured gap must not spin here.
        for (let i = 0; i < 50; i += 1) {
            const first = this.track.firstElementChild;
            const width = first.getBoundingClientRect().width + gap;

            if (this.track.scrollLeft < width) {
                break;
            }

            this.track.appendChild(first);
            this.track.scrollLeft -= width;
        }

        for (let i = 0; i < 50; i += 1) {
            if (this.track.scrollLeft > 0) {
                break;
            }

            const last = this.track.lastElementChild;
            const width = last.getBoundingClientRect().width + gap;

            this.track.insertBefore(last, this.track.firstElementChild);
            this.track.scrollLeft += width;
        }

        this.recycling = false;
    }

    _scrollTo(left) {
        this.track.scrollLeft = left;
        this._recycle();
    }

    /** How far the nearest card edge is from the track's leading edge. */
    _offsetToCard() {
        const edge = this.track.getBoundingClientRect().left
            + parseFloat(getComputedStyle(this.track).paddingLeft || 0);

        let nearest = 0;
        let best = Infinity;

        Array.from(this.track.children).forEach((item) => {
            const delta = item.getBoundingClientRect().left - edge;

            if (Math.abs(delta) < best) {
                best = Math.abs(delta);
                nearest = delta;
            }
        });

        return nearest;
    }

    /** Ease to the nearest card edge, so a released drag always lands on a card. */
    _settle() {
        const offset = this._offsetToCard();

        if (Math.abs(offset) > 0.5) {
            this._animate(offset, () => this._snap());
        } else {
            this._snap();
        }
    }

    /**
     * Land exactly. `_animate` accumulates fractional steps and `scrollLeft` rounds,
     * so a settle can finish a pixel or two short of the card edge.
     */
    _snap() {
        const offset = this._offsetToCard();

        if (Math.abs(offset) > 0.5 && Math.abs(offset) < 4) {
            this._scrollTo(this.track.scrollLeft + offset);
        }
    }

    // ---- arrows -----------------------------------------------------------

    _page(direction) {
        this._animate(this._step() * direction, () => this._snap());
    }

    _stop() {
        if (this.raf) {
            cancelAnimationFrame(this.raf);
            this.raf = null;
        }
    }

    /**
     * Ease the track by `distance`, a frame at a time. `behavior: 'smooth'` would be
     * cancelled the first time `_recycle()` rewrites `scrollLeft` mid-flight.
     */
    _animate(distance, onDone) {
        this._stop();

        const total = Math.abs(distance);
        const sign = Math.sign(distance);
        let travelled = 0;
        let previous = performance.now();

        const frame = (now) => {
            const elapsed = Math.min(now - previous, 32);
            previous = now;

            const remaining = total - travelled;
            // Ease out: cover a fixed share of what is left each millisecond.
            const move = Math.min(remaining, Math.max(1.2, remaining * elapsed * 0.011));

            travelled += move;
            this._scrollTo(this.track.scrollLeft + (move * sign));

            if (travelled < total - 0.5) {
                this.raf = requestAnimationFrame(frame);
            } else {
                this.raf = null;
                onDone?.();
                this._update();
            }
        };

        this.raf = requestAnimationFrame(frame);
    }

    _update() {
        if (this.loops) {
            // There is no end to reach.
            this.prev?.removeAttribute('disabled');
            this.next?.removeAttribute('disabled');

            return;
        }

        const max = this.track.scrollWidth - this.track.clientWidth;
        const left = Math.round(this.track.scrollLeft);

        this.prev?.toggleAttribute('disabled', left <= 0);
        this.next?.toggleAttribute('disabled', left >= max - 1);
    }
}
