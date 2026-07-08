// Tailwind 4 — admin-next plugin page.
//
// A minimal report over the last compile: it reads the persisted BuildManifest
// from the plugin's own API (GET /tailwind4/status) and lays the fields out as a
// readable list. A "Compile CSS" button posts to /tailwind4/compile, shows a
// busy state, toasts the result, and refreshes the report from the returned
// manifest.
//
// Fully self-contained (Shadow DOM), themed with admin-next CSS custom
// properties so it tracks the active admin theme, and written with logical CSS
// properties so it reads correctly in both LTR and RTL.

const TAG = window.__GRAV_PAGE_TAG;

class Tailwind4Page extends HTMLElement {
    _loading = true;
    _busy = false;
    _error = null;
    _theme = null;
    _compiled = false;
    _manifest = null;

    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

    connectedCallback() {
        this._loadStatus();
    }

    // ---- API helpers ----
    get _baseUrl() {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
    }

    _headers(json = false) {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        // X-API-Token, not Authorization: Bearer — some FastCGI/PHP-FPM setups
        // strip the Authorization header before it reaches PHP.
        if (token) h['X-API-Token'] = token;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async _api(method, path, body) {
        const opts = { method, headers: this._headers(!!body) };
        if (body) opts.body = JSON.stringify(body);
        const resp = await fetch(this._baseUrl + path, opts);
        const json = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            const detail = json && (json.detail || json.message || json.title);
            throw new Error(detail || `Request failed (${resp.status})`);
        }
        return json.data ?? json;
    }

    // ---- Data ----
    async _loadStatus() {
        this._loading = true;
        this._error = null;
        this._render();
        try {
            const data = await this._api('GET', '/tailwind4/status');
            this._applyStatus(data);
        } catch (e) {
            this._error = e.message;
        }
        this._loading = false;
        this._render();
    }

    _applyStatus(data) {
        this._theme = data.theme || null;
        this._compiled = !!data.compiled;
        this._manifest = data.manifest || null;
    }

    async _compile() {
        if (this._busy) return;
        this._busy = true;
        this._error = null;
        this._render();
        try {
            const data = await this._api('POST', '/tailwind4/compile', {});
            this._theme = data.theme || this._theme;
            this._compiled = true;
            this._manifest = data.manifest || null;

            const toast = data.toast || {};
            const type = toast.type === 'error' ? 'error' : 'success';
            const message = toast.message || data.message || 'Compile finished';
            window.__GRAV_TOAST?.[type]?.(message, type === 'error' ? { duration: 0 } : undefined);
        } catch (e) {
            this._error = e.message;
            window.__GRAV_TOAST?.error?.(`Compile failed: ${e.message}`, { duration: 0 });
        }
        this._busy = false;
        this._render();
    }

    // ---- Formatting ----
    _bytes(n) {
        if (typeof n !== 'number' || n < 1024) return `${n || 0} B`;
        const units = ['KB', 'MB', 'GB'];
        let v = n / 1024;
        let u = 0;
        while (v >= 1024 && u < units.length - 1) { v /= 1024; u++; }
        return `${v.toFixed(1).replace(/\.0$/, '')} ${units[u]}`;
    }

    _duration(ms) {
        if (typeof ms !== 'number') return '—';
        if (ms < 1000) return `${Math.round(ms)} ms`;
        return `${(ms / 1000).toFixed(2).replace(/\.?0+$/, '')} s`;
    }

    _datetime(ts) {
        if (!ts) return '—';
        try {
            return new Date(ts * 1000).toLocaleString();
        } catch (e) {
            return String(ts);
        }
    }

    _rows() {
        const m = this._manifest;
        if (!m) return [];
        return [
            ['Theme', m.theme || this._theme || '—'],
            ['Last compiled', this._datetime(m.timestamp)],
            ['Total duration', this._duration(m.duration_ms)],
            ['Engine compile time', this._duration(m.compile_ms)],
            ['Files scanned', m.files_scanned ?? '—'],
            ['Files read', m.files_read ?? '—'],
            ['Cache hits', m.cache_hits ?? '—'],
            ['Candidates', m.candidate_count ?? '—'],
            ['Output size', this._bytes(m.output_size)],
            ['Output path', m.output_path || '—'],
            ['Peak memory', this._bytes(m.peak_memory_bytes)],
            ['Tailwind engine', m.engine_version || '—'],
            ['Input hash', m.input_hash ? m.input_hash.slice(0, 16) + '…' : '—'],
        ];
    }

    // ---- Render ----
    _render() {
        const m = this._manifest;
        const failed = m && m.success === false;

        let body;
        if (this._loading) {
            body = `<p class="muted">Loading…</p>`;
        } else if (this._error && !m) {
            body = `<div class="alert error">${this._escape(this._error)}</div>`;
        } else if (!this._compiled || !m) {
            body = `
                <div class="empty">
                    <p class="muted">This theme has not been compiled yet.</p>
                    <p class="muted">Use <strong>Compile CSS</strong> to build the Tailwind stylesheet.</p>
                </div>`;
        } else {
            const statusBadge = failed
                ? `<span class="badge error">Failed</span>`
                : `<span class="badge success">Success</span>`;
            const errorLine = failed && m.error
                ? `<div class="alert error">${this._escape(m.error)}</div>`
                : '';
            const rows = this._rows()
                .map(([k, v]) => `
                    <div class="row">
                        <div class="key">${this._escape(k)}</div>
                        <div class="val">${this._escape(String(v))}</div>
                    </div>`)
                .join('');
            body = `
                <div class="status-line">${statusBadge}</div>
                ${errorLine}
                <div class="list">${rows}</div>`;
        }

        this.shadowRoot.innerHTML = `
            <style>
                :host { display: block; font-family: inherit; color: var(--foreground); }
                .wrap { max-width: 720px; }
                header {
                    display: flex; align-items: center; justify-content: space-between;
                    gap: 1rem; margin-block-end: 1.25rem; flex-wrap: wrap;
                }
                h1 { font-size: 1.35rem; margin: 0; }
                .sub { color: var(--muted-foreground); font-size: .85rem; margin-block-start: .25rem; }
                button.compile {
                    display: inline-flex; align-items: center; gap: .5rem;
                    background: var(--primary); color: #fff; border: 0;
                    padding: .55rem 1rem; border-radius: 6px; font-size: .9rem;
                    cursor: pointer; font-weight: 600;
                }
                button.compile:disabled { opacity: .6; cursor: default; }
                .spinner {
                    width: 14px; height: 14px; border-radius: 50%;
                    border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
                    animation: spin .7s linear infinite;
                }
                @keyframes spin { to { transform: rotate(360deg); } }
                .status-line { margin-block-end: 1rem; }
                .badge {
                    display: inline-block; padding: .15rem .6rem; border-radius: 999px;
                    font-size: .75rem; font-weight: 600;
                }
                .badge.success { background: color-mix(in srgb, green 18%, transparent); color: var(--foreground); }
                .badge.error { background: color-mix(in srgb, red 20%, transparent); color: var(--foreground); }
                .list {
                    border: 1px solid var(--border); border-radius: 8px; overflow: hidden;
                }
                .row {
                    display: grid; grid-template-columns: 190px 1fr; gap: 1rem;
                    padding: .6rem .9rem; border-block-end: 1px solid var(--border);
                }
                .row:last-child { border-block-end: 0; }
                .key { color: var(--muted-foreground); font-size: .85rem; }
                .val { font-size: .88rem; word-break: break-word; }
                .muted { color: var(--muted-foreground); }
                .empty { padding: 1.5rem 0; }
                .alert.error {
                    background: color-mix(in srgb, red 12%, transparent);
                    border: 1px solid color-mix(in srgb, red 35%, transparent);
                    padding: .7rem .9rem; border-radius: 6px; margin-block-end: 1rem;
                    font-size: .85rem;
                }
                @media (max-width: 520px) {
                    .row { grid-template-columns: 1fr; gap: .15rem; }
                }
            </style>
            <div class="wrap">
                <header>
                    <div>
                        <h1>Tailwind 4</h1>
                        <div class="sub">Compile the theme's Tailwind CSS from PHP — no Node, no build step.</div>
                    </div>
                    <button class="compile" id="compile-btn" ${this._busy ? 'disabled' : ''}>
                        ${this._busy ? '<span class="spinner"></span> Compiling…' : 'Compile CSS'}
                    </button>
                </header>
                ${body}
            </div>`;

        this.shadowRoot.getElementById('compile-btn')
            ?.addEventListener('click', () => this._compile());
    }

    _escape(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }
}

customElements.define(TAG, Tailwind4Page);
