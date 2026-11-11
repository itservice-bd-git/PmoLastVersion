// Constants and date helpers shared by every part of the Planning board (resources/js/planning/*).
// Dates are YYYY-MM-DD strings; shown dd/mm/yyyy in พ.ศ.
export const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
export const WEEKDAYS = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']; // Monday first
export const PRIORITIES = {
    none: { label: 'ไม่ระบุ', color: '#98a2b3' }, low: { label: 'ต่ำ', color: '#3b82f6' }, medium: { label: 'ปานกลาง', color: '#eab308' },
    high: { label: 'สูง', color: '#f97316' }, urgent: { label: 'ด่วน', color: '#ef4444' },
};
export const TH_DAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
export const PRIORITY_RANK = { urgent: 4, high: 3, medium: 2, low: 1, none: 0 };
export const TH_MON_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
export const AVATAR_COLORS = ['#2563eb', '#0e9384', '#d97706', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#7c3aed'];
export const MAX_LANES = 4; // bars per week before "+N"
export const SHIP_COLOR = '#0f766e';   // งานขนส่ง bars (teal-700): not a project status, so never mistaken for one
export const TL_DAY = 36;   // timeline column width (px)
export const pad = (n) => String(n).padStart(2, '0');
export const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
export const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return ymd(d); };
export const diffDays = (a, b) => Math.round((parse(a) - parse(b)) / 86400000);
export const hexToRgba = (hex, a) => { const n = parseInt(hex.slice(1), 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; };
// dates are shown dd/mm/yyyy in พ.ศ. (like the rest of this page: "ตุลาคม 2569"); the value sent / stored stays YYYY-MM-DD
export const beFormat = (date, fmt) => (fmt === 'Y-m-d' ? ymd(date) : pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + (date.getFullYear() + 543));
export const beParse = (str, fmt) => {
    if (!str) return undefined;
    if (fmt === 'Y-m-d') return parse(str);
    const m = String(str).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return undefined;
    const y = Number(m[3]) > 2400 ? Number(m[3]) - 543 : Number(m[3]), d = new Date(y, Number(m[2]) - 1, Number(m[1]));
    return d.getMonth() === Number(m[2]) - 1 ? d : undefined;
};
export const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
