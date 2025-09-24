import Plugin from 'src/plugin-system/plugin.class';

export default class CategoryFiltersShowMorePlugin extends Plugin {

    static options = {

        /**
         * Show all button selector
         */
        showAllButtonSelector: '.filter-show-all',

    };

    init() {
        document.querySelector('.filter-panel-wrapper-toggle').addEventListener('click', () => {
            const filtersShowAllResponsive = document.querySelectorAll('.offcanvas .filter-panel-items-container .filter-show-all');
            this._registerEvents(filtersShowAllResponsive);
        })

        const filtersShowAll = this.el.querySelectorAll(this.options.showAllButtonSelector);
        this._registerEvents(filtersShowAll);
    }

    _registerEvents(filtersShowAll) {
        for (let i = 0; i < filtersShowAll.length; i++) {
            filtersShowAll[i].addEventListener("click", function () {

                const parent = this.parentElement;
                const state = this.dataset.state;
                const removable = parent.querySelectorAll('.removable');
                const show = this.querySelector('.show');
                const hide = this.querySelector('.hide');

                if (state == 'show') {
                    hide.style.display = "block";
                    show.style.display = "none";
                    this.dataset.state = "hide";
                    for (let i = 0; i < removable.length; ++i) {
                        removable[i].classList.remove('hidden');
                    }
                }

                if (state == 'hide') {
                    hide.style.display = "none";
                    show.style.display = "block";
                    this.dataset.state = "show";
                    for (let i = 0; i < removable.length; ++i) {
                        removable[i].classList.add('hidden');
                    }
                }

            });
        }
    }

}

