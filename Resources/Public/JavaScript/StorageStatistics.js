(function () {
    'use strict';

    const sortableTables = document.querySelectorAll('[data-sortable-table]');

    sortableTables.forEach((table) => {
        const tbody = table.tBodies.item(0);
        if (!tbody) {
            return;
        }

        const headers = table.querySelectorAll('.size-storage-sort-button');
        let activeSort = createDefaultSort(headers);

        if (activeSort !== null) {
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

                const rows = Array.from(tbody.rows).map((row, index) => ({
                    row,
                    index,
                    value: readSortValue(row, key, type),
                }));

                rows.sort((left, right) => compareRows(left, right, direction, type));
                rows.forEach(({row}) => tbody.appendChild(row));

                activeSort = {key, direction};
                updateHeaderState(headers, activeSort);
            });
        });
    });

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
}());
