import Alpine from 'alpinejs';
import flatpickr from 'flatpickr';
import Sortable from 'sortablejs';

window.Alpine = Alpine;
window.Sortable = Sortable;
window.flatpickr = flatpickr; // for date fields created after page load (e.g. the project modal on My Department)

Alpine.start();

/**
 * Native <input type="date"> renders its calendar/text display in the
 * visitor's own browser/OS locale (often mm/dd/yyyy), which the server can't
 * override. flatpickr replaces the visible display with a text input we
 * fully control (dd/mm/yyyy everywhere), while the original input stays
 * hidden and keeps submitting the yyyy-mm-dd value forms already expect -
 * no backend or validation changes needed.
 */
function initDatePickers(root = document) {
    root.querySelectorAll('input[type="date"]:not([data-flatpickr-init])').forEach((el) => {
        el.setAttribute('data-flatpickr-init', '1');
        flatpickr(el, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            altInputClass: el.className,
            allowInput: true,
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initDatePickers());

// Exposed so pages that insert new date inputs via fetch (no full reload)
// can enhance them the same way without duplicating this setup.
window.initDatePickers = initDatePickers;

/**
 * Small self-dismissing toast, for async save/delete/reorder feedback that
 * shouldn't block interaction the way alert() does. Plain CSS (not Tailwind
 * utilities) since this markup is built in JS and never scanned by Tailwind's
 * content globs.
 */
function showToast(message, type = 'success') {
    let container = document.getElementById('app-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'app-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'app-toast' + (type === 'error' ? ' app-toast-error' : '');
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('app-toast-show'));
    setTimeout(() => {
        toast.classList.remove('app-toast-show');
        setTimeout(() => toast.remove(), 200);
    }, 2500);
}

window.showToast = showToast;
