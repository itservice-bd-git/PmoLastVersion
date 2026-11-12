// Inside Planning's รอบส่ง window (planning/partials/_overlays): a page drawn with x-embed-layout (its <body data-embed>)
// tells the window when one of its panels opens or closes (its header dims, one ✕ to press), and when Esc is pressed
// with none open (the window closes). A panel open = the body locked by it (overflow-hidden / overflow-y-hidden) or the
// photo viewer on screen. Called from app.js before Alpine starts, so its Esc listener runs before Alpine's.
export default function embedBridge() {
    if (window.parent === window || !document.body?.hasAttribute('data-embed')) return;
    const tell = (m) => window.parent.postMessage(m, location.origin);
    const busy = () => document.body.classList.contains('overflow-hidden') || document.body.classList.contains('overflow-y-hidden') || !!document.querySelector('[data-file-viewer]');
    // capture: what is open is read before Esc closes it
    window.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !busy()) tell({ type: 'pmo-ship-close' }); }, true);
    let open = false;
    new MutationObserver(() => { const now = busy(); if (now !== open) { open = now; tell({ type: 'pmo-ship-panel', open }); } })
        .observe(document.body, { attributes: true, attributeFilter: ['class'], childList: true, subtree: true });
    // the round that was pressed, in view
    const picked = document.querySelector('[data-picked]');
    if (picked) picked.scrollIntoView({ block: 'center' });
}
