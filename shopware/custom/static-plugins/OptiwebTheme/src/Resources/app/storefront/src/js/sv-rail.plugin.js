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
 *     leave them unbound; recycling the real node keeps every binding intact. The one
 *     exception is `cloneToLoop`, opt-in per rail, for a track whose items are inert
 *     and too few to loop on their own — the home hero's two photographs.
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
        /**
         * Duplicate the track's items until there is runway to loop (see
         * `_fillForLoop`). OFF by default and deliberately so: a product card carries
         * a wishlist button and a buy form, and a clone of those is an unbound
         * duplicate — the whole reason `_recycle` moves real nodes instead of cloning.
         * Opt in with `data-sv-rail-options='{"cloneToLoop":true}'` only where the
         * items are inert, as the home hero's two photographs are.
         */
        cloneToLoop: false,
        /** Hard ceiling on cloning, so a mis-measured track cannot fill the DOM. */
        maxItems: 12,
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
        // The set to clone from, captured before `_recycle` starts reordering.
        this.originals = Array.from(this.track.children);

        this._fillForLoop();
        this._measure();
        this._registerEvents();
        this._update();
    }

    /** The widest item in the track — the unit of runway the loop needs. */
    _widest() {
        return Array.from(this.track.children)
            .reduce((max, el) => Math.max(max, el.getBoundingClientRect().width), 0);
    }

    /**
     * Looping needs TWO items of runway, not one.
     *
     * `_recycle` moves the first item to the end and pulls `scrollLeft` back by its
     * width, so the scroll position has to be able to travel a whole item PAST the one
     * being moved. With exactly one item of overflow the position is already pinned at
     * its maximum when the recycle fires: the forward pass shifts the order and the
     * backward pass immediately shifts it back, the two cancel, and the rail sits
     * still. That is precisely what the home hero did — two full-width slides give
     * exactly one item of runway — and it read as "the arrows are dead".
     *
     * Below that threshold the rail is an honest finite scroller with end-stopped
     * arrows, which works. `>= widest` was the old test and is the bug.
     */
    _measure() {
        const overflow = this.track.scrollWidth - this.track.clientWidth;
        const widest = this._widest();

        this.loops = overflow > 1 && overflow >= widest * 2;
        this.el.classList.toggle('sv-rail--static', overflow <= 1);
        this.el.classList.toggle('sv-rail--loops', this.loops);
    }

    /**
     * Clone the item set until the track has the two items of runway `_measure` wants.
     * Only ever called when `cloneToLoop` is set — see the option's note.
     *
     * A track with no box of its own (the hero above 720 puts both the wrapper and the
     * track at `display: contents`) measures zero and is skipped: there is nothing to
     * scroll there, and cloning against a zero client width would just run to the cap.
     * The resize handler calls this again, so crossing into the slider layout still
     * gets its clones.
     */
    _fillForLoop() {
        if (!this.options.cloneToLoop || !this.track.clientWidth) {
            return;
        }

        while (this.track.children.length + this.originals.length <= this.options.maxItems
            && this.track.scrollWidth - this.track.clientWidth < this._widest() * 2) {
            this.originals.forEach((item) => {
                const clone = item.cloneNode(true);

                clone.classList.add('sv-rail__item--clone');
                // A clone is the same picture twice over as far as a screen reader is
                // concerned, and it must not collect a tab stop of its own.
                clone.setAttribute('aria-hidden', 'true');
                clone.querySelectorAll('a, button, input, select, textarea')
                    .forEach((el) => el.setAttribute('tabindex', '-1'));

                this.track.appendChild(clone);
            });
        }
    }

    _registerEvents() {
        this.prev?.addEventListener('click', () => this._page(-1));
        this.next?.addEventListener('click', () => this._page(1));

        this.track.addEventListener('scroll', () => {
            this._recycle();
            this._update();
        }, { passive: true });

        window.addEventListener('resize', () => {
            // Cloning first: a rail that only becomes a slider below a breakpoint has
            // no box to measure until it gets there.
            this._fillForLoop();
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
            this.velocity = 0;
            this.lastMoveAt = performance.now();
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

                // Rolling velocity for the flick on release. Smoothed, because a raw
                // last-frame delta is noisy enough that an ordinary drag reads as a
                // flick roughly one time in three.
                const now = performance.now();
                const dt = Math.max(1, now - (this.lastMoveAt || now));

                this.velocity = (this.velocity || 0) * 0.7 + (dx / dt) * 0.3;
                this.lastMoveAt = now;
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
                // A quick flick should carry on rather than stopping dead under the
                // finger. Anything slower just settles onto the nearest card edge.
                const speed = Math.abs(this.velocity || 0);

                if (speed > 0.45 && !this._reducedMotion()) {
                    // px/ms -> cards, capped so a hard flick cannot fling the whole rail.
                    const cards = Math.min(3, Math.max(1, Math.round(speed * 1.6)));

                    this._page(-Math.sign(this.velocity) * cards);
                } else {
                    this._settle();
                }

                this.velocity = 0;
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

        // Snap has to be off for the two writes below, exactly as it is for an
        // animation frame (see `_animate`). This runs from the scroll event too, which
        // arrives a tick AFTER the animation released snapping — and with snapping
        // live the browser re-snapped between the forward and backward passes, so the
        // rail advanced two slides instead of one on a phone. The `getBoundingClientRect`
        // in the loop forces the style recalc that makes the class take effect, and the
        // position is back on a snap point before it comes off again.
        const wasScripted = this.el.classList.contains('is--scripted');

        if (!wasScripted) {
            this.el.classList.add('is--scripted');
        }

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

        if (!wasScripted) {
            this.el.classList.remove('is--scripted');
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

    /**
     * `amount` is in cards: +1 is one card forward, -2 two back.
     *
     * Finishes on `_settle`, not `_snap`. From an aligned rail the two are the same —
     * a whole number of cards lands on a card edge and `_settle` finds nothing to do.
     * They differ after a flick, which starts wherever the finger let go: `_snap` only
     * corrects offsets under 4px, so a full-width slide (the home hero) came to rest
     * showing two half photographs.
     */
    _page(amount) {
        this._animate(this._step() * amount, () => this._settle());
    }

    _stop() {
        if (this.raf) {
            cancelAnimationFrame(this.raf);
            this.raf = null;
        }
    }

    /**
     * Ease the track by `distance`. Two constraints shape this:
     *
     *  - `behavior: 'smooth'` is unusable: `_recycle()` rewrites `scrollLeft`
     *    mid-flight and the browser cancels the smooth scroll the moment it does.
     *  - For the same reason the tween cannot interpolate towards an absolute target,
     *    because the target moves. So it accumulates an eased *distance* and applies
     *    the delta since the previous frame, which survives recycling.
     *
     * The curve is a fixed-duration cubic ease-out. The previous version decayed by a
     * share of the remaining distance each frame with a 1.2px floor, which has no
     * defined end and spends its last third crawling — the "doesn't feel normal" part.
     */
    _animate(distance, onDone) {
        this._stop();

        // Snap off for the duration. `component/_rail.scss` turns `scroll-snap-type:
        // x mandatory` on wherever the pointer is coarse — i.e. on every phone — and
        // the browser re-snaps after each of the `scrollLeft` writes below, including
        // the one `_recycle` makes when it moves an item between the ends. The result
        // on a real phone was an arrow that worked exactly once and then stuck. The
        // rail is aligned on a snap point by the time the class comes off, so handing
        // the scrolling back changes nothing.
        this.el.classList.add('is--scripted');

        const total = Math.abs(distance);
        const sign = Math.sign(distance);

        const finish = () => {
            this.raf = null;
            onDone?.();

            // A callback may have started another leg — `_page` settles onto the
            // nearest card edge when it lands. Keep snapping suppressed until that
            // one finishes too, or it fights the tail of the movement.
            if (!this.raf) {
                this.el.classList.remove('is--scripted');
            }

            this._update();
        };

        // Someone who has asked the OS for less motion gets the position, not the trip.
        if (total < 1 || this._reducedMotion()) {
            this._scrollTo(this.track.scrollLeft + distance);
            finish();

            return;
        }

        // Long throws take a little longer than short ones, but not proportionally —
        // a flat duration makes a one-card nudge feel sluggish and a full page abrupt.
        const duration = Math.min(560, 260 + total * 0.35);
        const started = performance.now();
        let applied = 0;

        const frame = (now) => {
            const t = Math.min(1, (now - started) / duration);
            // Cubic ease-out: quick departure, settled arrival.
            const eased = (1 - ((1 - t) ** 3)) * total;
            const move = eased - applied;

            applied = eased;
            this._scrollTo(this.track.scrollLeft + (move * sign));

            if (t < 1) {
                this.raf = requestAnimationFrame(frame);
            } else {
                finish();
            }
        };

        this.raf = requestAnimationFrame(frame);
    }

    _reducedMotion() {
        return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
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
