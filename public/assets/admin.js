document.querySelector('#form-error, #action-notice')?.focus();

const dialog = document.querySelector('#confirmation-dialog');
if (dialog) {
    const title = dialog.querySelector('#confirmation-title');
    const action = dialog.querySelector('button[value="confirm"]');
    const defaultTitle = title.textContent;
    const defaultAction = action.textContent;
    let pendingForm;
    const confirmed = new WeakSet();
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (confirmed.delete(form)) return;
            event.preventDefault();
            pendingForm = form;
            dialog.querySelector('#confirmation-message').textContent = form.dataset.confirm;
            title.textContent = form.dataset.confirmTitle ?? defaultTitle;
            action.textContent = form.dataset.confirmAction ?? defaultAction;
            dialog.returnValue = 'cancel';
            dialog.showModal();
        });
        form.querySelector('button[type="submit"]').disabled = false;
    }
    dialog.addEventListener('close', () => {
        // A queued close event may arrive after the dialog has reopened.
        if (dialog.open) return;
        const form = pendingForm;
        pendingForm = undefined;
        if (dialog.returnValue === 'confirm' && form) {
            confirmed.add(form);
            form.requestSubmit();
        }
    });
}
