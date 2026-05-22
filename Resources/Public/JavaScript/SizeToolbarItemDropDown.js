(function () {
    'use strict';

    const storageKey = 'tx_size.toolbar.totalExpanded';
    const toggleSelector = '[data-size-storage-toggle]';
    const panelSelector = '[data-size-storage-panel]';

    const readExpandedState = () => {
        try {
            return window.localStorage.getItem(storageKey) === 'true';
        } catch (error) {
            return false;
        }
    };

    const writeExpandedState = (expanded) => {
        try {
            window.localStorage.setItem(storageKey, expanded ? 'true' : 'false');
        } catch (error) {
            // Ignore unavailable storage and keep the UI functional.
        }
    };

    const setExpandedState = (toggle, panel, expanded) => {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.classList.toggle('is-expanded', expanded);
        panel.classList.toggle('is-expanded', expanded);

        if (expanded) {
            panel.hidden = false;
            panel.style.height = 'auto';
            panel.style.opacity = '1';
            return;
        }

        panel.hidden = true;
        panel.style.height = '0px';
        panel.style.opacity = '0';
    };

    const animatePanel = (toggle, panel, expanded) => {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.classList.toggle('is-expanded', expanded);
        panel.classList.toggle('is-expanded', expanded);

        if (expanded) {
            panel.hidden = false;
            panel.style.height = '0px';
            panel.style.opacity = '0';

            requestAnimationFrame(() => {
                panel.style.height = panel.scrollHeight + 'px';
                panel.style.opacity = '1';
            });

            return;
        }

        panel.style.height = panel.scrollHeight + 'px';
        panel.style.opacity = '1';

        requestAnimationFrame(() => {
            panel.style.height = '0px';
            panel.style.opacity = '0';
        });
    };

    const initializeDropdown = (dropdown) => {
        const toggle = dropdown.querySelector(toggleSelector);
        const panel = dropdown.querySelector(panelSelector);

        if (!(toggle instanceof HTMLButtonElement) || panel === null) {
            return;
        }

        setExpandedState(toggle, panel, readExpandedState());

        panel.addEventListener('transitionend', (event) => {
            if (event.propertyName !== 'height') {
                return;
            }

            if (toggle.getAttribute('aria-expanded') === 'true') {
                panel.style.height = 'auto';
                return;
            }

            panel.hidden = true;
        });

        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') !== 'true';
            animatePanel(toggle, panel, expanded);
            writeExpandedState(expanded);
        });
    };

    const initialize = () => {
        document.querySelectorAll('.size-storage-dropdown').forEach((dropdown) => {
            initializeDropdown(dropdown);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
        return;
    }

    initialize();
}());
