import Alpine from 'alpinejs';
import flatpickr from 'flatpickr';
import Sortable from 'sortablejs';
import checklistEditor from './checklist-editor';
import registerPlanningBoard from './planning';
import registerDeliveriesPage from './deliveries';
import registerActivityTree from './activity-tree';
import registerAssignment from './assignment';
import registerWorkSummary from './work-summary';
import registerTemplateEditor from './template-editor';
import { registerWorkDays } from './work-days';
import pmoProjectDates from './project-dates';
import watchSession from './session-expiry';

window.Alpine = Alpine;
window.Sortable = Sortable;
window.pmoProjectDates = pmoProjectDates;   // x-data of "เลื่อน / แก้วัน" (projects/partials/_dates), also fetched into Planning
window.flatpickr = flatpickr; // for date fields created after page load (e.g. the project modal on My Department)

Alpine.data('checklistEditor', checklistEditor);   // x-checklist-editor (cabinet + template pages)
// Settings > สีสถานะ Checklist: every list of the cabinet page puts its items' statuses here (ownerId => [{ id, done, tags }]),
// so "สถานะในตู้นี้" and the Task headers add them up as they change
Alpine.store('checklistTags', {
    lists: {},
    tally(owners = null) {
        const n = {};
        Object.entries(this.lists).forEach(([o, items]) => {
            if (owners && !owners.includes(Number(o))) return;
            items.forEach((i) => i.tags.forEach((t) => { n[t] = n[t] || { n: 0 }; n[t].n++; }));
        });
        return (window.pmoChecklistStatuses || []).filter((s) => n[s.id]).map((s) => ({ ...s, ...n[s.id] }));
    },
});
registerPlanningBoard();                           // /planning/board's x-data="planningBoard(...)" 
registerDeliveriesPage();                          // /deliveries' x-data="deliveriesPage(...)"
registerActivityTree();                             // x-activity-tree
registerAssignment();                               // cabinet / project / dispatch: handing work to departments
registerWorkSummary();                              // reports/today
registerTemplateEditor();                           // cabinet-templates/show
registerWorkDays();                                // "จำนวนวัน" beside a Sub Task's start / due dates (work-days.js)
watchSession();                                    // logged out (left open too long): back to log in, then to this page - before Alpine starts, so its first calls are watched too
Alpine.start();

/**
 * Dates are shown dd/mm/yyyy in พ.ศ. everywhere (e.g. 15/09/2569); the value a form sends / a page stores stays
 * yyyy-mm-dd (ค.ศ.). Typing accepts either year (2569 or 2026).
 */
const pad2 = (n) => String(n).padStart(2, '0');
const thaiFormat = (date, fmt) => {
    if (fmt === 'Y-m-d') return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    const day = pad2(date.getDate()) + '/' + pad2(date.getMonth() + 1) + '/' + (date.getFullYear() + 543);
    return fmt.includes('H') ? day + ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes()) : day;
};
const thaiParse = (str, fmt) => {
    if (!str) return undefined;
    const s = String(str).trim();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return undefined;
    const y = Number(m[3]) > 2400 ? Number(m[3]) - 543 : Number(m[3]);
    const d = new Date(y, Number(m[2]) - 1, Number(m[1]));
    return d.getMonth() === Number(m[2]) - 1 ? d : undefined;
};
window.thaiDate = (iso) => { const d = thaiParse(iso); return d ? thaiFormat(d, 'd/m/Y') : ''; };
window.thaiFormat = thaiFormat;   // for date fields made by page scripts (flatpickr({ formatDate: window.thaiFormat, parseDate: window.thaiParse }))
window.thaiParse = thaiParse;

/**
 * Native <input type="date"> renders its display in the visitor's own browser/OS locale (often mm/dd/yyyy, ค.ศ.), which
 * the server can't override. flatpickr shows a dd/mm/yyyy (พ.ศ.) text field instead while the original input stays (hidden)
 * and keeps the yyyy-mm-dd value - so forms, x-model and @change handlers work unchanged. Inputs added later (Alpine
 * templates, fetch) are picked up too, and the visible field follows the original: its value set from code, disabled,
 * hidden (x-show) and classes.
 */
const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

function enhanceDate(el) {
    el.setAttribute('data-flatpickr-init', '1');
    // disableMobile: on a phone flatpickr would put the phone's own date field in instead (its locale, often ค.ศ.) - the
    // พ.ศ. calendar is the same on every screen
    const fp = flatpickr(el, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', formatDate: thaiFormat, parseDate: thaiParse, altInputClass: el.className, allowInput: true, disableMobile: true });
    const alt = fp.altInput;
    if (!alt) return;
    // a <label for> of the original (now hidden) input points at the field people see and type in
    if (el.id) {
        alt.id = el.id + '-shown';
        document.querySelectorAll('label[for="' + CSS.escape(el.id) + '"]').forEach((l) => { l.htmlFor = alt.id; });
    }
    let syncing = false;
    // a value set from code (x-model, :value, el.value = …) shows in the visible field too
    Object.defineProperty(el, 'value', {
        configurable: true,
        get() { return nativeValue.get.call(this); },
        set(v) {
            nativeValue.set.call(this, v);
            if (syncing) return;
            syncing = true;
            try { fp.setDate(v || null, false); } finally { syncing = false; }
        },
    });
    const mirror = () => {
        alt.disabled = el.disabled;
        alt.style.display = el.style.display === 'none' ? 'none' : '';
        alt.className = el.className.split(/\s+/).filter((c) => c && c !== 'flatpickr-input' && c !== 'active').join(' ') + ' flatpickr-input';
    };
    mirror();
    new MutationObserver(mirror).observe(el, { attributes: true, attributeFilter: ['disabled', 'style', 'class'] });
}

// not one already done, nor a field flatpickr made itself (its phone field, .flatpickr-mobile): enhancing that one made
// flatpickr add another, and so on - the page froze on phones as soon as a form with a date opened (ส่งถึงแล้ว, 2026-11)
const DATE_TODO = 'input[type="date"]:not([data-flatpickr-init]):not(.flatpickr-mobile)';

function initDatePickers(root = document) {
    root.querySelectorAll(DATE_TODO).forEach(enhanceDate);
}

document.addEventListener('DOMContentLoaded', () => {
    initDatePickers();
    // date inputs that appear later (Alpine x-if / x-for, fetched HTML); a removed one takes its calendar with it
    new MutationObserver((records) => {
        for (const r of records) {
            r.addedNodes.forEach((n) => { if (n.nodeType === 1) { if (n.matches && n.matches('input[type="date"]')) { if (n.matches(DATE_TODO)) enhanceDate(n); } else initDatePickers(n); } });
            r.removedNodes.forEach((n) => {
                if (n.nodeType !== 1) return;
                (n._flatpickr ? [n] : [...(n.querySelectorAll ? n.querySelectorAll('[data-flatpickr-init]') : [])]).forEach((x) => { if (x._flatpickr) x._flatpickr.destroy(); });
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
});

/**
 * A photo from a phone's camera (3-5 MB) made small before it goes up - quicker on a site's mobile data, and well under
 * the server's upload limits: its long side to 1600 px, JPEG. Anything else (PDF, a small picture) as it is; a picture the
 * browser cannot draw too. Used by งานจัดส่ง's ส่งถึงแล้ว and the driver's page (driver/show).
 */
window.pmoShrinkImage = function (file, max = 1600) {
    if (!/^image\/(jpeg|png)$/.test(file.type || '') || file.size < 700 * 1024) return Promise.resolve(file);
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file), img = new Image();
        img.onload = () => {
            const k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
            const c = document.createElement('canvas');
            c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            c.toBlob((blob) => resolve(blob && blob.size < file.size
                ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified }) : file), 'image/jpeg', 0.82);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
};

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

// "ไม่รับงาน": opens the reason dialog (components/reject-dialog, in layouts/app); resolves to the reason, or null when cancelled
window.askRejectReason = ({ title, detail } = {}) => new Promise((resolve) => {
    window.dispatchEvent(new CustomEvent('ask-reject-reason', { detail: { title, detail, resolve } }));
});

// A page's tab (?tab=...) kept in the address bar as it changes, so the browser's Back / a reload / a shared link
// returns to the same tab. The default tab is left out of the URL.
window.keepTabInUrl = (tab, defaultTab) => {
    try {
        const u = new URL(location.href);
        if (tab === defaultTab) u.searchParams.delete('tab'); else u.searchParams.set('tab', tab);
        history.replaceState(history.state, '', u);
    } catch (e) { /* old browser: the tab is just not remembered */ }
};
