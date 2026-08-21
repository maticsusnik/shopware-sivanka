import Plugin from 'src/plugin-system/plugin.class';

export default class OwMegaMenuPlugin extends Plugin {
    init() {
        this.dropdownMenu = this.el.querySelector('.dropdown-menu');
        this.content = this.el.querySelector('.navigation-flyout-content');
        this.maxHeight = 0;

        if (!this.dropdownMenu || !this.content) {
            return;
        }

        this.levelContainers = {
            0: Array.from(this.content.querySelectorAll('.navigation-flyout-categories.is-level-0')),
            1: Array.from(this.content.querySelectorAll('.navigation-flyout-categories.is-level-1')),
            2: Array.from(this.content.querySelectorAll('.navigation-flyout-categories.is-level-2')),
            3: Array.from(this.content.querySelectorAll('.navigation-flyout-categories.is-level-3')),
            4: Array.from(this.content.querySelectorAll('.navigation-flyout-categories.is-level-4')),
        };

        this._registerObservers();
        this._registerEvents();
        this._resetColumns();
    }

    _registerObservers() {
        if (window.innerWidth < 992) {
            return;
        }

        this._dropdownWasOpen = this.dropdownMenu.classList.contains('show');

        this._dropdownClassObserver = new MutationObserver(() => {
            const open = this.dropdownMenu.classList.contains('show');
            if (open && !this._dropdownWasOpen) {
                this.maxHeight = 0;
                this.dropdownMenu.style.minHeight = '';
                this._resetColumnsWithoutHeightUpdate();
                this._scheduleHeightUpdate();
            }
            this._dropdownWasOpen = open;
        });

        this._dropdownClassObserver.observe(this.dropdownMenu, {
            attributes: true,
            attributeFilter: ['class'],
        });
    }

    _resetColumnsWithoutHeightUpdate() {
        Object.keys(this.levelContainers).forEach(levelKey => {
            const level = parseInt(levelKey, 10);
            const containers = this.levelContainers[level];

            if (!containers) {
                return;
            }

            const links = this.content.querySelectorAll(`.navigation-flyout-link.is-level-${level}`);
            links.forEach(link => link.classList.remove('is-active'));

            if (level === 0) {
                containers.forEach(container => container.classList.add('is-visible'));
            } else {
                containers.forEach(container => container.classList.remove('is-visible'));
            }
        });
    }

    _scheduleHeightUpdate() {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this._updateHeight();
            });
        });
    }

    _registerEvents() {
        if (window.innerWidth < 992) {
            return;
        }

        this.el.addEventListener('mouseenter', () => {
            this.maxHeight = 0;
            this.dropdownMenu.style.minHeight = '';
            this._resetColumns();
            this._scheduleHeightUpdate();
        });

        const links = this.content.querySelectorAll('.navigation-flyout-link');

        links.forEach(link => {
            const level = this._getLevelFromLink(link);
            if (level === null) {
                return;
            }

            const childContainer = this._findChildContainer(link, level + 1);

            if (!childContainer) {
                return;
            }

            link.classList.add('has-children');

            link.addEventListener('mouseenter', () => {
                this._activateLink(link, level, childContainer);
            });

            link.addEventListener('focus', () => {
                this._activateLink(link, level, childContainer);
            });
        });

        this.dropdownMenu.addEventListener('mouseleave', () => {
            this._resetColumns();
            this._updateHeight();
        });
    }

    _getLevelFromLink(link) {
        const levelMatch = Array.from(link.classList).find(cls => cls.startsWith('is-level-'));
        if (!levelMatch) {
            return null;
        }

        const level = parseInt(levelMatch.replace('is-level-', ''), 10);

        if (Number.isNaN(level)) {
            return null;
        }

        return level;
    }

    _findChildContainer(link, childLevel) {
        if (!this.levelContainers[childLevel] || !this.levelContainers[childLevel].length) {
            return null;
        }

        const parentCol = link.closest('.navigation-flyout-col');

        if (!parentCol) {
            return null;
        }

        const localChild = parentCol.querySelector(`.navigation-flyout-categories.is-level-${childLevel}`);

        if (localChild) {
            return localChild;
        }

        return this.levelContainers[childLevel][0] || null;
    }

    _activateLink(link, level, childContainer) {
        const levelLinks = this.content.querySelectorAll(`.navigation-flyout-link.is-level-${level}`);
        levelLinks.forEach(l => l.classList.remove('is-active'));
        link.classList.add('is-active');

        const containers = this.levelContainers[level + 1] || [];
        containers.forEach(container => container.classList.remove('is-visible'));
        childContainer.classList.add('is-visible');

        Object.keys(this.levelContainers)
            .map(lvl => parseInt(lvl, 10))
            .filter(lvl => lvl > level + 1)
            .forEach(lvl => {
                this.levelContainers[lvl].forEach(container => container.classList.remove('is-visible'));
                const deeperLinks = this.content.querySelectorAll(`.navigation-flyout-link.is-level-${lvl}`);
                deeperLinks.forEach(l => l.classList.remove('is-active'));
            });

        this._scheduleHeightUpdate();
    }

    _resetColumns() {
        Object.keys(this.levelContainers).forEach(levelKey => {
            const level = parseInt(levelKey, 10);
            const containers = this.levelContainers[level];

            if (!containers) {
                return;
            }

            const links = this.content.querySelectorAll(`.navigation-flyout-link.is-level-${level}`);
            links.forEach(link => link.classList.remove('is-active'));

            if (level === 0) {
                containers.forEach(container => container.classList.add('is-visible'));
            } else {
                containers.forEach(container => container.classList.remove('is-visible'));
            }
        });

        this._scheduleHeightUpdate();
    }

    _updateHeight() {
        if (!this.dropdownMenu) {
            return;
        }

        const dropdownRect = this.dropdownMenu.getBoundingClientRect();
        if (dropdownRect.height === 0) {
            return;
        }

        let maxBottom = dropdownRect.top;

        const links = this.dropdownMenu.querySelectorAll('.navigation-flyout-link');
        links.forEach(link => {
            const col = link.closest('.navigation-flyout-categories');
            if (!col) {
                return;
            }

            const isVisibleColumn =
                col.classList.contains('is-level-0') || col.classList.contains('is-visible');

            if (!isVisibleColumn) {
                return;
            }

            const rect = link.getBoundingClientRect();
            maxBottom = Math.max(maxBottom, rect.bottom);
        });

        const extraSpacing = 24;
        const targetHeight = Math.max(0, maxBottom - dropdownRect.top + extraSpacing);

        if (targetHeight > this.maxHeight) {
            this.maxHeight = targetHeight;
        }

        if (this.maxHeight > 0) {
            this.dropdownMenu.style.minHeight = `${this.maxHeight}px`;
        }
    }
}
