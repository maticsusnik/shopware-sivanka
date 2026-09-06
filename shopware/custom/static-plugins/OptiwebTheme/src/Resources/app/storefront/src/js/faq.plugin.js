import Plugin from 'src/plugin-system/plugin.class';

/**
 * FAQ accordion.
 *
 * The whole card is the hit target, not just the heading text — the card carries the
 * padding, so binding to the `<h5>` left a dead band around it.
 */
export default class OptiwebFaqPlugin extends Plugin {
    init() {
        // Scoped to this instance. A bare `document.querySelectorAll` bound every card
        // once per accordion on the page, so a second one made each click toggle twice.
        this.items = Array.from(this.el.querySelectorAll('.ow-faq__single'));

        this.items.forEach((item) => {
            const question = item.querySelector('.ow-faq__single-question');
            const answer = item.querySelector('.ow-faq__single-answer');

            if (question) {
                question.setAttribute('role', 'button');
                question.setAttribute('tabindex', '0');
                question.setAttribute('aria-expanded', 'false');
            }

            item.addEventListener('click', () => this._toggle(item));

            question?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                    event.preventDefault();
                    this._toggle(item);
                }
            });

            // A link inside an answer must not fold the card back up.
            answer?.addEventListener('click', (event) => event.stopPropagation());
        });
    }

    _toggle(item) {
        const willOpen = !item.classList.contains('open');

        this.items.forEach((other) => {
            other.classList.remove('open');
            other.querySelector('.ow-faq__single-question')
                ?.setAttribute('aria-expanded', 'false');
        });

        if (willOpen) {
            item.classList.add('open');
            item.querySelector('.ow-faq__single-question')
                ?.setAttribute('aria-expanded', 'true');
        }
    }
}
