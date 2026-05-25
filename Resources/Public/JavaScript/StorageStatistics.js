(function () {
    'use strict';

    const moduleElement = document.querySelector('.size-storage-module');
    const sortableTables = document.querySelectorAll('[data-sortable-table]');
    const scrollTopButton = document.querySelector('[data-size-scroll-top]');

    sortableTables.forEach((table) => {
        const tbody = table.tBodies.item(0);
        if (!tbody) {
            return;
        }

        const headers = table.querySelectorAll('.size-storage-sort-button');
        let activeSort = createDefaultSort(headers);

        if (activeSort !== null) {
            sortTableRows(tbody, activeSort.key, activeSort.direction, inferSortType(headers, activeSort.key));
            updateHeaderState(headers, activeSort);
        }

        headers.forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.sortKey;
                const type = button.dataset.sortType === 'number' ? 'number' : 'text';
                if (!key) {
                    return;
                }

                let direction = type === 'number' ? 'desc' : 'asc';
                if (activeSort !== null && activeSort.key === key) {
                    direction = activeSort.direction === 'asc' ? 'desc' : 'asc';
                }

                sortTableRows(tbody, key, direction, type);
                activeSort = {key, direction};
                updateHeaderState(headers, activeSort);
            });
        });
    });

    initializeScrollTopButton(moduleElement, scrollTopButton);

    function sortTableRows(tbody, key, direction, type) {
        const rows = Array.from(tbody.rows).map((row, index) => ({
            row,
            index,
            value: readSortValue(row, key, type),
        }));

        rows.sort((left, right) => compareRows(left, right, direction, type));
        rows.forEach(({row}) => tbody.appendChild(row));
    }

    function readSortValue(row, key, type) {
        const attributeName = key.replace(/[A-Z]/g, (match) => '-' + match.toLowerCase());
        const rawValue = row.getAttribute('data-' + attributeName);

        if (type === 'number') {
            if (rawValue === null || rawValue === '') {
                return null;
            }

            const parsedValue = Number(rawValue);
            return Number.isFinite(parsedValue) ? parsedValue : null;
        }

        const titleValue = key === 'label' ? (row.getAttribute('data-title') || '') : '';
        return ((rawValue || '') + ' ' + titleValue).toLocaleLowerCase();
    }

    function compareRows(left, right, direction, type) {
        if (type === 'number') {
            if (left.value === null && right.value === null) {
                return left.index - right.index;
            }
            if (left.value === null) {
                return 1;
            }
            if (right.value === null) {
                return -1;
            }
        }

        if (left.value < right.value) {
            return direction === 'asc' ? -1 : 1;
        }
        if (left.value > right.value) {
            return direction === 'asc' ? 1 : -1;
        }

        return left.index - right.index;
    }

    function updateHeaderState(headers, activeSort) {
        headers.forEach((button) => {
            const isActive = button.dataset.sortKey === activeSort.key;
            button.classList.toggle('is-active', isActive);
            button.dataset.sortDirection = isActive ? activeSort.direction : '';

            const headerCell = button.closest('th');
            if (headerCell) {
                headerCell.setAttribute('aria-sort', isActive ? (activeSort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            }
        });
    }

    function createDefaultSort(headers) {
        const defaultButton = headers.item(headers.length - 1);
        if (!defaultButton || !defaultButton.dataset.sortKey) {
            return null;
        }

        return {
            key: defaultButton.dataset.sortKey,
            direction: 'desc',
        };
    }

    function inferSortType(headers, key) {
        const matchingButton = Array.from(headers).find((button) => button.dataset.sortKey === key);
        return matchingButton?.dataset.sortType === 'number' ? 'number' : 'text';
    }

    function initializeScrollTopButton(moduleElement, button) {
        if (!moduleElement || !button) {
            return;
        }

        const scrollContainer = findScrollContainer(moduleElement);
        const listenerTarget = scrollContainer === window ? window : scrollContainer;
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        const updateVisibility = () => {
            button.classList.toggle('is-visible', getScrollTop(scrollContainer) > 240);
        };

        listenerTarget.addEventListener('scroll', updateVisibility, {passive: true});
        window.addEventListener('resize', updateVisibility, {passive: true});
        button.addEventListener('click', () => {
            const behavior = prefersReducedMotion.matches ? 'auto' : 'smooth';

            if (scrollContainer === window) {
                window.scrollTo({top: 0, behavior});
                return;
            }

            scrollContainer.scrollTo({top: 0, behavior});
        });

        updateVisibility();
    }

    function findScrollContainer(element) {
        let currentElement = element.parentElement;

        while (currentElement) {
            const overflowY = window.getComputedStyle(currentElement).overflowY;
            if (/(auto|scroll|overlay)/.test(overflowY) && currentElement.scrollHeight > currentElement.clientHeight) {
                return currentElement;
            }
            currentElement = currentElement.parentElement;
        }

        return window;
    }

    function getScrollTop(scrollContainer) {
        if (scrollContainer === window) {
            return window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
        }

        return scrollContainer.scrollTop;
    }
}());
