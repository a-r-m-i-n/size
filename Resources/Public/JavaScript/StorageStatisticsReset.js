import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

const triggerSelector = '[data-size-reset-trigger]';

const submitForm = (form) => {
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
    }

    form.submit();
};

const initializeResetTrigger = (button) => {
    const formId = button.dataset.sizeResetFormId;
    if (!formId) {
        return;
    }

    const form = document.getElementById(formId);
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    button.addEventListener('click', () => {
        Modal.advanced({
            title: button.dataset.sizeResetModalTitle || '',
            content: button.dataset.sizeResetModalMessage || '',
            severity: Severity.warning,
            buttons: [
                {
                    text: button.dataset.sizeResetModalCancel || 'Cancel',
                    active: true,
                    btnClass: 'btn-default',
                    trigger: (_, modal) => {
                        modal.hideModal();
                    },
                },
                {
                    text: button.dataset.sizeResetModalConfirm || 'Reset',
                    btnClass: 'btn-warning',
                    trigger: (_, modal) => {
                        modal.hideModal();
                        submitForm(form);
                    },
                },
            ],
        });
    });
};

const initialize = () => {
    document.querySelectorAll(triggerSelector).forEach((button) => {
        if (button instanceof HTMLButtonElement) {
            initializeResetTrigger(button);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
    initialize();
}
