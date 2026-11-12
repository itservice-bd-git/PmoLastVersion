export default function pushDevice(cfg) {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const b64 = (s) => { const p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map((c) => c.charCodeAt(0))); };
    const call = (url, method, body) => fetch(url, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: body ? JSON.stringify(body) : undefined })
        .then(async (r) => { const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status)); return d; });
    return {
        supported: false, on: false, busy: false, ok: false, message: 'กำลังตรวจสอบ...', reg: null,
        async init() {
            if (!cfg.key) { this.message = 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า Web Push บนเซิร์ฟเวอร์ — ใช้กระดิ่งในแอปได้ตามปกติ'; return; }
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) { this.message = 'เบราว์เซอร์นี้ไม่รองรับการแจ้งเตือนตอนปิดแอป (iPhone/iPad: เพิ่มเว็บไปที่หน้าจอโฮมก่อน)'; return; }
            if (!window.isSecureContext) { this.message = 'ต้องเปิดผ่าน https การแจ้งเตือนตอนปิดแอปถึงจะใช้ได้'; return; }
            this.supported = true;
            try { this.reg = await navigator.serviceWorker.register(cfg.sw); const sub = await this.reg.pushManager.getSubscription(); this.on = !!sub; } catch (e) { this.message = 'ลงทะเบียนไม่สำเร็จ: ' + e.message; return; }
            this.paint();
        },
        paint() {
            this.ok = this.on;
            this.message = Notification.permission === 'denied' ? 'เบราว์เซอร์บล็อกการแจ้งเตือนไว้ — อนุญาตที่ไอคอน 🔒 หน้า URL แล้วรีโหลด' : (this.on ? '✓ เปิดอยู่บนเครื่องนี้' : 'ปิดอยู่บนเครื่องนี้');
        },
        async enable() {
            this.busy = true;
            try {
                if (await Notification.requestPermission() !== 'granted') throw new Error('ไม่ได้รับอนุญาตการแจ้งเตือน');
                const sub = await this.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.key) });
                await call(cfg.subscribe, 'POST', sub.toJSON());
                this.on = true;
            } catch (e) { window.showToast && window.showToast('เปิดไม่สำเร็จ: ' + e.message, 'error'); }
            this.busy = false; this.paint();
        },
        async disable() {
            this.busy = true;
            try { const sub = await this.reg.pushManager.getSubscription(); if (sub) { await call(cfg.subscribe, 'DELETE', { endpoint: sub.endpoint }); await sub.unsubscribe(); } this.on = false; }
            catch (e) { window.showToast && window.showToast('ปิดไม่สำเร็จ: ' + e.message, 'error'); }
            this.busy = false; this.paint();
        },
        async test() {
            this.busy = true;
            try { const d = await call(cfg.test, 'POST'); window.showToast && window.showToast('ส่งทดสอบแล้ว (' + d.devices + ' เครื่อง)', 'success'); }
            catch (e) { window.showToast && window.showToast(e.message, 'error'); }
            this.busy = false;
        },
    };
}
