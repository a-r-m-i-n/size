(() => {
    const storageKey = 't3.size.storageUsage.showDetails';

    const applyVisibility = (container, showDetails) => {
        container.querySelectorAll('[data-size-detail]').forEach((element) => {
            element.hidden = !showDetails;
        });
        container.querySelectorAll('[data-size-divider]').forEach((element) => {
            element.hidden = !showDetails;
        });
    };

    const initializeContainer = (container) => {
        const checkbox = container.querySelector('[data-size-details-toggle]');
        if (!checkbox || checkbox.dataset.sizeDetailsInitialized === '1') {
            return;
        }

        const storedValue = window.localStorage.getItem(storageKey);
        const showDetails = storedValue === null ? true : storedValue === '1';

        checkbox.checked = showDetails;
        applyVisibility(container, showDetails);

        checkbox.addEventListener('change', () => {
            const nextValue = checkbox.checked;
            window.localStorage.setItem(storageKey, nextValue ? '1' : '0');
            applyVisibility(container, nextValue);
        });

        checkbox.dataset.sizeDetailsInitialized = '1';
    };

    document.querySelectorAll('[data-size-dropdown]').forEach(initializeContainer);
})();
