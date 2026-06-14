// Guard Helper — plugin page (admin-next, component mode).
//
// The full browser-only Guard Agent setup, reached from the "Guard" sidebar
// item (/plugin/guard-helper). Shows install status + requirements and lets a
// super-admin download + verify + unpack + pair the standalone agent in one
// click, then displays the single-use pairing code to enter in Guard Cloud.
// Backed by GuardController (GET /guard-helper/status, POST /guard-helper/setup).
//
// The element tag is injected by admin-next as window.__GRAV_PAGE_TAG.
const TAG = window.__GRAV_PAGE_TAG;

class GuardHelperPage extends HTMLElement {
	constructor() {
		super();
		this.attachShadow({ mode: 'open' });
		this._state = { phase: 'loading', data: null, error: null, result: null, busy: false };
	}

	connectedCallback() {
		this._render();
		this._loadStatus();
	}

	// ── API helpers ────────────────────────────────────────────────────────
	_apiBase() {
		return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
	}

	_headers(json) {
		const h = {};
		const token = window.__GRAV_API_TOKEN;
		// X-API-Token, not Authorization: Bearer — FastCGI/PHP-FPM can strip the
		// Authorization header before it reaches PHP.
		if (token) h['X-API-Token'] = token;
		if (json) h['Content-Type'] = 'application/json';
		return h;
	}

	async _api(method, path, body) {
		const opts = { method, headers: this._headers(!!body) };
		if (body) opts.body = JSON.stringify(body);
		const resp = await fetch(this._apiBase() + path, opts);
		let json = null;
		try { json = await resp.json(); } catch (_) { /* empty body */ }
		if (!resp.ok) {
			const msg = json?.error?.detail || json?.error?.message || json?.detail || json?.message
				|| `Request failed (${resp.status})`;
			throw new Error(msg);
		}
		return json?.data ?? json;
	}

	// ── Actions ────────────────────────────────────────────────────────────
	async _loadStatus() {
		this._patch({ phase: 'loading', error: null });
		try {
			const data = await this._api('GET', '/guard-helper/status');
			this._patch({ phase: 'ready', data });
		} catch (e) {
			this._patch({ phase: 'error', error: e.message });
		}
	}

	async _setup() {
		if (this._state.busy) return;
		const ok = window.__GRAV_DIALOGS
			? await window.__GRAV_DIALOGS.confirm({
				title: 'Set up Guard Agent',
				message: 'Download, verify, and install the Guard Agent into this site, then start pairing? '
					+ 'A signed release is fetched from your Guard Cloud URL and verified before anything is written.',
				confirmLabel: 'Set up',
			})
			: true;
		if (!ok) return;

		this._patch({ busy: true, error: null });
		try {
			const result = await this._api('POST', '/guard-helper/setup', {});
			this._patch({ busy: false, result, data: { ...(this._state.data || {}), installed: true } });
		} catch (e) {
			this._patch({ busy: false, error: e.message });
		}
	}

	async _copy(text) {
		try { await navigator.clipboard.writeText(text); } catch (_) { /* clipboard blocked */ }
	}

	async _repair() {
		if (this._state.busy) return;
		this._patch({ busy: true, error: null });
		try {
			const result = await this._api('POST', '/guard-helper/repair', {});
			this._patch({ busy: false, result });
		} catch (e) {
			this._patch({ busy: false, error: e.message });
		}
	}

	_patch(patch) {
		this._state = { ...this._state, ...patch };
		this._render();
	}

	// ── Render ─────────────────────────────────────────────────────────────
	_render() {
		this.shadowRoot.innerHTML = `<style>${this._css()}</style><div class="wrap">${this._body(this._state)}</div>`;
		this.shadowRoot.getElementById('setup-btn')?.addEventListener('click', () => this._setup());
		this.shadowRoot.getElementById('repair-btn')?.addEventListener('click', () => this._repair());
		this.shadowRoot.getElementById('retry-btn')?.addEventListener('click', () => this._loadStatus());
		this.shadowRoot.querySelectorAll('[data-copy]').forEach((el) =>
			el.addEventListener('click', () => this._copy(el.getAttribute('data-copy'))));
	}

	_codeRow(label, value) {
		return `<div class="field">
			<div class="lbl">${esc(label)}</div>
			<div class="coderow">
				<code class="code">${esc(value)}</code>
				<button class="btn ghost sm" data-copy="${esc(value)}" title="Copy">Copy</button>
			</div></div>`;
	}

	_signup(s) {
		const url = s.data?.signup_url || 'https://gravguard.com';
		if (s.result) {
			return `<div class="signup"><strong>Next:</strong> enter the code in Guard Cloud.
				No account yet? <a href="${esc(url)}" target="_blank" rel="noopener">Create a free account at gravguard.com</a>.</div>`;
		}
		return `<p class="muted signup-foot">No Guard Cloud account yet?
			<a href="${esc(url)}" target="_blank" rel="noopener">Sign up free at gravguard.com</a>.</p>`;
	}

	_body(s) {
		if (s.phase === 'loading') {
			return `<div class="card"><div class="muted">Checking agent status…</div></div>`;
		}
		if (s.phase === 'error') {
			return `<div class="card"><div class="err">${esc(s.error)}</div>
				<button id="retry-btn" class="btn ghost">Retry</button></div>`;
		}

		const cloud = s.data?.cloud_url || '';

		if (s.result) {
			const ttlMin = Math.round((s.result.ttl || 0) / 60);
			return `<div class="card">
				<div class="status ok"><span class="dot"></span> Guard Agent installed — pairing started</div>
				<p class="muted">Enter these in <strong>Guard Cloud → Fleet → Add Site</strong> within ${ttlMin} minutes. The code is single-use; reload to start a new window if it expires.</p>
				${this._codeRow('Pairing code', s.result.code)}
				${this._codeRow('Agent endpoint', s.result.endpoint || s.result.endpoint_path || '')}
				${this._signup(s)}
			</div>`;
		}

		if (s.data?.installed) {
			return `<div class="card">
				<div class="status ok"><span class="dot"></span> Guard Agent is active</div>
				<p class="muted">Installed and ready. To add this site to Guard Cloud — or if the last code expired — generate a fresh pairing code (it's single-use).</p>
				${this._codeRow('Agent endpoint', s.data.endpoint || s.data.endpoint_path || '')}
				${s.error ? `<div class="err">${esc(s.error)}</div>` : ''}
				<button id="repair-btn" class="btn ghost" ${s.busy ? 'disabled' : ''}>${s.busy ? 'Working…' : 'Show pairing code'}</button>
				${this._signup(s)}
			</div>`;
		}

		// ext-sodium / ext-zip ship with PHP and are enabled on virtually every
		// host, so we don't advertise them as "requirements" — we only warn if
		// this particular server is actually missing one.
		const req = s.data?.requirements || {};
		const missing = [];
		if (req.sodium === false) missing.push('ext-sodium');
		if (req.zip === false) missing.push('ext-zip');
		const reqBad = missing.length > 0;
		const warn = reqBad
			? `<div class="warn">This server is missing ${missing.join(' and ')}. Ask your host to enable ${missing.length > 1 ? 'them' : 'it'} before setting up the agent.</div>`
			: '';

		return `<div class="card">
			<div class="status off"><span class="dot"></span> Not set up</div>
			<p class="muted">This installs the standalone Guard Agent into <code>_guard/</code> and starts pairing — no shell, no crontab. A signed release is downloaded from <code>${esc(cloud)}</code> and verified before anything is written.</p>
			${s.error ? `<div class="err">${esc(s.error)}</div>` : ''}
			${warn}
			<button id="setup-btn" class="btn" ${s.busy || reqBad ? 'disabled' : ''}>${s.busy ? 'Setting up…' : 'Set up Guard Agent'}</button>
			${this._signup(s)}
		</div>`;
	}

	_css() {
		return `
		:host { display:block; }
		.wrap { max-width:640px; }
		.card { display:flex; flex-direction:column; gap:14px;
			padding:24px; border:1px solid var(--border,#e4e4e7); border-radius:12px;
			background:var(--card,var(--background,#fff)); color:var(--foreground,#09090b);
			font:14px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
		.status { display:flex; align-items:center; gap:8px; font-weight:600; font-size:15px; }
		.dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
		.ok .dot { background:#16a34a; } .ok { color:#16a34a; }
		.off .dot { background:#d97706; } .off { color:#d97706; }
		.muted { color:var(--muted-foreground,#71717a); margin:0; }
		.field { display:flex; flex-direction:column; gap:5px; }
		.lbl { font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
			color:var(--muted-foreground,#71717a); }
		.coderow { display:flex; gap:8px; align-items:stretch; }
		.code { flex:1; font-family:ui-monospace,Menlo,monospace; word-break:break-all;
			background:var(--muted,#f4f4f5); border:1px solid var(--border,#e4e4e7);
			border-radius:8px; padding:10px 12px; font-size:13px; }
		code { font-family:ui-monospace,Menlo,monospace; background:var(--muted,#f4f4f5);
			padding:1px 5px; border-radius:4px; font-size:.92em; }
		.err { color:#dc2626; background:color-mix(in oklab,#dc2626 10%,transparent);
			border:1px solid color-mix(in oklab,#dc2626 30%,transparent); border-radius:8px; padding:10px 12px; }
		.warn { color:#d97706; font-size:13px; }
		.btn { align-self:flex-start; border:0; border-radius:8px; padding:11px 18px; font-weight:600; font-size:14px;
			cursor:pointer; background:var(--primary,#2463eb); color:var(--primary-foreground,#fff); }
		.btn:hover:not(:disabled) { filter:brightness(1.05); }
		.btn:disabled { opacity:.6; cursor:default; }
		.btn.ghost { background:transparent; color:var(--foreground,#09090b); border:1px solid var(--border,#e4e4e7); }
		.btn.sm { padding:6px 12px; font-size:12px; }
		.signup { padding:12px 14px; border-radius:8px; font-size:13px;
			background:color-mix(in oklab,var(--primary,#2463eb) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--primary,#2463eb) 25%,transparent); }
		.signup a, .signup-foot a { color:var(--primary,#2463eb); font-weight:600; }
		.signup-foot { padding-top:14px; border-top:1px solid var(--border,#e4e4e7); }`;
	}
}

function esc(v) {
	return String(v ?? '').replace(/[&<>"']/g, (c) =>
		({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

customElements.define(TAG, GuardHelperPage);
