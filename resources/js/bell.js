// the top bar's bell (layouts/app): polls notifications.feed; a new urgent notice opens the status-report alert window
export default function pmoBell(cfg) {
    // a notice is "new" when it was not on the list yet, or the project's notice gained an event (its time / latest line moved)
    const stamp = (n) => n.at + '|' + (n.latest || n.body || '');
    const items = (f) => f.todo.concat(f.feed);
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    return {
        cfg, feed: cfg.feed, open: false, tab: 'all', fresh: new Set(),
        seen: new Set(cfg.feed.urgent), known: new Map(items(cfg.feed).map((n) => [n.id, stamp(n)])), started: false,
        init() {
            if (this.started) return;   // Alpine calls init() itself, and x-init too
            this.started = true;
            const poll = () => { if (!document.hidden) this.refresh(); };
            setInterval(poll, 15000);   // same pace as Planning's refresh
            document.addEventListener('visibilitychange', poll);
        },
        // "ใหม่" while the bell is open shows what was new when it opened (the server already counts it seen)
        isNew(n) { return n.new || this.fresh.has(n.id); },
        toggle() {
            if (this.open) { this.close(); return; }
            this.open = true;
            this.fresh = new Set(items(this.feed).filter((n) => n.new).map((n) => n.id));
            if (this.feed.unread > 0 || this.fresh.size) { this.feed.unread = 0; this.post(cfg.seen); }
        },
        close() { this.open = false; this.fresh = new Set(); },
        ack(n) { n.acked = true; this.post(cfg.ack.replace('__ID__', n.id)); },
        ackAll() { this.feed.todo.forEach((n) => { n.acked = true; }); this.post(cfg.ackAll); },
        async post(url) {
            try {
                const res = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() } });
                if (res.ok) this.take(await res.json(), false);
            } catch (e) { /* offline: the next refresh catches up */ }
        },
        async refresh() {
            try {
                const res = await fetch(cfg.url, { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.take(await res.json(), true);
            } catch (e) { /* offline: keep what we have */ }
        },
        take(feed, announce) {
            const fresh = feed.urgent.filter((id) => !this.seen.has(id));
            feed.urgent.forEach((id) => this.seen.add(id));
            const news = items(feed).filter((n) => n.new && this.known.get(n.id) !== stamp(n));
            items(feed).forEach((n) => this.known.set(n.id, stamp(n)));
            this.feed = feed;
            if (!announce) return;
            if (fresh.length) window.dispatchEvent(new CustomEvent('pmo-urgent'));   // urgent: the alert window
            // everything new also shows as a small card at the top right (does not block the work)
            news.slice(0, 3).reverse().forEach((n) => window.dispatchEvent(new CustomEvent('pmo-notice', { detail: { ...n,
                readUrl: cfg.read.replace('__ID__', n.id), ackUrl: n.todo ? cfg.ack.replace('__ID__', n.id) : null } })));
        },
    };
}
