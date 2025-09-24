import Plugin from 'src/plugin-system/plugin.class';

export default class CategoryDescriptionReadMore extends Plugin {

    init() {
        this._CategoryDescriptionReadMore();
    }

    _CategoryDescriptionReadMore() {
        this.readMoreBlock = this.el;
        this.lines = parseInt(this.el.getAttribute('data-lines')) ?? 2;
        this.inline = this.el.getAttribute('data-inline') === 'true';
        this.showLessButton = this.el.getAttribute('data-show-less-button') === 'true';

        if (!this.readMoreBlock) {
            return;
        }
        this.readMoreBlockText = this.readMoreBlock.querySelector('.cms-element-text');

        if (!this.readMoreBlockText || this.readMoreBlockText.innerText === '') {
            return;
        }

        const textLength = this.readMoreBlockText.innerText.length;
        const textLimit = this.lines * 100;

        this._setLinesClamp(false);

        if (textLength > textLimit) {
            this._showMoreButton(this.readMoreBlock, this.readMoreBlockText);
        }
    }

    _showMoreButton(block, blockText) {
        let self = this;
        const readmore = document.createElement('div');
        readmore.classList.add('ow-read-more-toggle');
        readmore.innerHTML = window.translations.show;
        if(block.classList.contains('inline')) {
            blockText.appendChild(readmore);
        } else {
            block.appendChild(readmore);
        }

        readmore.addEventListener('click', function (event) {
            block.classList.toggle('toggled');
            self._setLinesClamp(block.classList.contains('toggled'));
            if (self.showLessButton) {
                this.innerHTML = !block.classList.contains('toggled') ? window.translations.show : window.translations.hide;
            } else {
                readmore.remove();
            }
        });
    }

    _setLinesClamp(showAll) {
        this.readMoreBlockText.style = ['-webkit-line-clamp', showAll ? 'unset' : this.lines].join(':');
    }
}

