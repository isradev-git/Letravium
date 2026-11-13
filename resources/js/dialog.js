const returnFocusStack = [];
const initialValues = new WeakMap();

function focusFirst(dialog) {
    const target = dialog.querySelector('[autofocus]')
        || dialog.querySelector('input:not([type="hidden"]), select, textarea')
        || dialog.querySelector('[data-dialog-safe]')
        || dialog.querySelector('[data-dialog-close]');

    target?.focus();
}

function fieldValues(dialog) {
    return [...dialog.querySelectorAll('input, select, textarea')].map((field) => [
        field,
        field.type === 'checkbox' ? field.checked : field.value,
    ]);
}

function rememberValues(dialog) {
    initialValues.set(dialog, fieldValues(dialog));
}

function isDirty(dialog) {
    const initial = initialValues.get(dialog);

    if (!initial) {
        return false;
    }

    return fieldValues(dialog).some(([field, value], index) => {
        const [, previous] = initial[index] ?? [];

        return field.type === 'checkbox' ? field.checked !== previous : value !== previous;
    });
}

function openDialog(dialog, trigger = null) {
    if (!dialog) {
        return;
    }

    if (dialog.open) {
        focusFirst(dialog);

        return;
    }

    returnFocusStack.push(trigger || document.activeElement);
    rememberValues(dialog);

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    } else {
        dialog.setAttribute('open', '');
    }

    document.body.style.overflow = 'hidden';
    focusFirst(dialog);
}

function closeDialog(dialog) {
    if (!dialog) {
        return;
    }

    if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
    } else {
        dialog.removeAttribute('open');
        const target = returnFocusStack.pop();

        if (target instanceof HTMLElement && target.isConnected) {
            target.focus();
        }
    }
}

export function initDialogs() {
    const dialogs = [...document.querySelectorAll('dialog[data-dialog]')];

    if (!dialogs.length) {
        return;
    }

    const novelForm = document.querySelector('[data-novel-form]');
    const novelUniverse = document.querySelector('[data-novel-universe]');

    const syncNovelForm = () => {
        if (novelForm && novelUniverse && novelUniverse.value) {
            novelForm.action = '/workspace/universes/' + novelUniverse.value + '/novels';
        }
    };

    novelUniverse?.addEventListener('change', syncNovelForm);
    syncNovelForm();

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-dialog-open]');

        if (opener) {
            event.preventDefault();

            if (opener.dataset.dialogUniverse && novelUniverse) {
                novelUniverse.value = opener.dataset.dialogUniverse;
                syncNovelForm();
            }

            openDialog(document.getElementById(opener.dataset.dialogOpen), opener);

            return;
        }

        const closer = event.target.closest('[data-dialog-close]');

        if (closer) {
            event.preventDefault();
            closeDialog(closer.closest('dialog'));
        }
    });

    for (const dialog of dialogs) {
        dialog.addEventListener('close', () => {
            const target = returnFocusStack.pop();

            if (!document.querySelector('dialog[open]')) {
                document.body.style.overflow = '';
            }

            if (target instanceof HTMLElement && target.isConnected) {
                target.focus();
            }
        });

        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeDialog(dialog);
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog && !isDirty(dialog)) {
                closeDialog(dialog);
            }
        });
    }

    for (const error of document.querySelectorAll('dialog[data-dialog] [data-dialog-error]')) {
        const dialog = error.closest('dialog');

        if (!dialog) {
            continue;
        }

        const opener = [...document.querySelectorAll('[data-dialog-open="' + dialog.id + '"]')]
            .find((candidate) => candidate.offsetParent !== null);

        if (dialog.open && !dialog.matches(':modal')) {
            dialog.removeAttribute('open');
        }

        openDialog(dialog, opener || null);
    }
}
