export default function noticeRules({ rules, storeUrl, depts, old }) {
    const blank = () => ({ event: 'tick', department_id: '', cabinet_template_id: '', days: 2, repeat: false, message: '', recipients: ['linked'], is_active: true });
    return {
        rules, storeUrl, editing: old && old.rule_id && rules[old.rule_id] ? Number(old.rule_id) : null,
        // a form sent back with an error opens again as it was typed
        f: old ? Object.assign(blank(), old, { recipients: old.recipients || [], repeat: old.repeat === '1', is_active: old.is_active !== '0' }) : blank(),
        openNew() { this.editing = null; this.f = blank(); this.$dispatch('open-modal', 'notice-rule'); },
        openEdit(id) {
            const r = this.rules[id];
            this.editing = id;
            this.f = { event: r.event, department_id: r.department_id, cabinet_template_id: r.cabinet_template_id, days: r.days, repeat: r.repeat, message: r.message, recipients: [...r.recipients], is_active: r.is_active };
            this.$dispatch('open-modal', 'notice-rule');
        },
        insert(v) {
            const el = this.$refs.msg, at = el.selectionStart ?? this.f.message.length;
            this.f.message = this.f.message.slice(0, at) + v + this.f.message.slice(at);
            this.$nextTick(() => { el.focus(); el.setSelectionRange(at + v.length, at + v.length); });
        },
        countUsers() { return this.f.recipients.filter((r) => r.startsWith('user:')).length; },
        preview() {
            const dept = depts[this.f.department_id] || 'แผนก';
            const n = this.f.event === 'before' || this.f.event === 'late' ? String(this.f.days || 1) : '1';
            const text = (this.f.message || '…').split('{รายการ}').join('"Breaker 100A"').split('{ตู้}').join('WH/MO/00001')
                .split('{วันที่}').join('15/11/2569').split('{n}').join(n).split('{โครงการ}').join('SO-ตัวอย่าง').split('{แผนก}').join(dept);
            return dept + ': ' + text;
        },
    };
}
