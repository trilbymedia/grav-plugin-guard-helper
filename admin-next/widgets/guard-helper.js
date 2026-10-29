// Guard Helper — dashboard widget (admin-next).
//
// A compact card on the admin-next dashboard that shows whether the standalone
// Guard Agent is set up, and lets a super-admin install + pair it in one click
// (downloads a signed release, verifies it, unpacks into <grav-root>/_guard,
// and starts an in-process pairing window). Backed by GuardController:
//   GET  /guard-helper/status
//   POST /guard-helper/setup
//
// The element tag is injected by admin-next as window.__GRAV_WIDGET_TAG.
const TAG = window.__GRAV_WIDGET_TAG;

// Lucide "shield-check", inlined so the widget needs no icon dependency.
const SHIELD = `<svg class="ico" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
	stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
	<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
	<path d="m9 12 2 2 4-4"/></svg>`;

class GuardHelperWidget extends HTMLElement {
	constructor() {
		super();
		this.attachShadow({ mode: 'open' });
		this._state = { phase: 'loading', data: null, error: null, result: null, busy: false };
	}

	connectedCallback() {
		this._render();
		this._loadStatus();
	}

	get _statusEndpoint() {
		return this.getAttribute('data-endpoint') || '/guard-helper/status';
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
			const data = await this._api('GET', this._statusEndpoint);
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
				message: 'Download, verify, and install the Guard Agent into this site, then start pairing?',
				confirmLabel: 'Set up',
			})
			: true;
		if (!ok) return;

		this._patch({ busy: true, error: null });
		try {
			const result = await this._api('POST', '/guard-helper/setup', {});
			this._patch({ busy: false, result, justInstalled: true, data: { ...(this._state.data || {}), installed: true, connection: 'unpaired' } });
		} catch (e) {
			this._patch({ busy: false, error: e.message });
		}
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
		const s = this._state;
		this.shadowRoot.innerHTML = `<style>${this._css()}</style>${this._body(s)}`;
		this.shadowRoot.getElementById('setup-btn')?.addEventListener('click', () => this._setup());
		this.shadowRoot.getElementById('repair-btn')?.addEventListener('click', () => this._repair());
		this.shadowRoot.getElementById('retry-btn')?.addEventListener('click', () => this._loadStatus());
		// Move the admin's own router rather than reloading the whole app.
		this.shadowRoot.querySelector('a.more')?.addEventListener('click', (e) => {
			if (typeof window.__GRAV_NAVIGATE === 'function') { e.preventDefault(); window.__GRAV_NAVIGATE(pageUrl()); }
		});
	}

	_body(s) {
		const head = `<div class="head">${SHIELD}<span class="ttl">Guard Agent</span></div>`;

		if (s.phase === 'loading') {
			return `<div class="card">${head}<div class="muted">Checking…</div></div>`;
		}
		if (s.phase === 'error') {
			return `<div class="card">${head}<div class="err">${esc(s.error)}</div>
				<button id="retry-btn" class="btn ghost">Retry</button></div>`;
		}

		const installed = s.result || s.data?.installed;
		const signup = s.data?.signup_url || 'https://gravguard.com';

		if (installed) {
			// The same three states as the Guard page: not paired yet, paired
			// but not reached yet, connected.
			const conn = s.data?.connection || 'unpaired';
			let state;
			if (s.justInstalled && conn === 'unpaired') state = ['off', 'Installed, pairing started'];
			else if (conn === 'connected') state = ['ok', 'Connected to Guard Cloud'];
			else if (conn === 'waiting') state = ['off', 'Paired, waiting for Guard Cloud'];
			else state = ['off', 'Installed, not paired yet'];

			const head2 = `<div class="${state[0]}">● ${esc(state[1])}</div>`;
			const err = s.error ? `<div class="err">${esc(s.error)}</div>` : '';

			if (s.result) {
				const ttlMin = Math.round((s.result.ttl || 0) / 60);
				return `<div class="card">${head}${head2}
					<div class="lbl">Pairing code</div>
					<div class="code">${esc(s.result.code)}</div>
					<p class="muted">Enter it in Guard Cloud → Fleet → Add Site within ${ttlMin} min.</p>
					<p class="muted signup">No account? <a href="${esc(signup)}" target="_blank" rel="noopener">Sign up free at ${esc(host(signup))}</a>.</p></div>`;
			}
			if (conn === 'unpaired') {
				return `<div class="card">${head}${head2}
					<p class="muted">Enter a pairing code in Guard Cloud → Fleet → Add Site to add this site to your fleet.</p>
					${err}
					<button id="repair-btn" class="btn" ${s.busy ? 'disabled' : ''}>${s.busy ? 'Working…' : 'Show pairing code'}</button></div>`;
			}
			const d = s.data?.delivery || {};
			const seen = Math.max(d.last_tick_at || 0, d.last_push_at || 0);
			const sub = conn === 'connected'
				? `<p class="muted">Last contact ${esc(ago(seen))}.</p>`
				: `<p class="muted">Guard Cloud hasn't contacted this site yet; that usually takes a few minutes.</p>`;
			return `<div class="card">${head}${head2}
				${sub}
				<div class="lbl">Endpoint</div>
				<div class="code">${esc(s.data?.endpoint || s.data?.endpoint_path || '')}</div>
				<a class="more" href="${esc(pageUrl())}">Open the Guard page →</a></div>`;
		}

		// Only flag the bundled PHP extensions when one is genuinely missing.
		const req = s.data?.requirements || {};
		const miss = [];
		if (req.sodium === false) miss.push('ext-sodium');
		if (req.zip === false) miss.push('ext-zip');
		const reqBad = miss.length > 0;
		const warn = reqBad ? `<div class="warn">Missing ${miss.join(' and ')} — ask your host to enable ${miss.length > 1 ? 'them' : 'it'}.</div>` : '';
		return `<div class="card">${head}
			<div class="off">● Not set up</div>
			<p class="muted">Install the agent to enable fleet updates, backups, and monitoring.</p>
			${s.error ? `<div class="err">${esc(s.error)}</div>` : ''}
			${warn}
			<button id="setup-btn" class="btn" ${s.busy || reqBad ? 'disabled' : ''}>${s.busy ? 'Setting up…' : 'Set up Guard Agent'}</button></div>`;
	}

	_css() {
		return `
		:host { --gh-ok: var(--success, #16a34a); --gh-warn: var(--warning, #d97706); --gh-bad: var(--destructive, #dc2626);
			--gh-ok-fg: color-mix(in oklab, var(--gh-ok) 74%, var(--foreground, #09090b));
			--gh-warn-fg: color-mix(in oklab, var(--gh-warn) 62%, var(--foreground, #09090b));
			--gh-bad-fg: color-mix(in oklab, var(--gh-bad) 55%, var(--foreground, #09090b)); }
		:host { display:block; height:100%; }
		.card { display:flex; flex-direction:column; gap:8px; height:100%; box-sizing:border-box;
			padding:16px; border:1px solid var(--border,#e4e4e7); border-radius:12px;
			background:var(--card,var(--background,#fff)); color:var(--foreground,#09090b);
			font:13px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
		.head { display:flex; align-items:center; gap:8px; }
		.ico { width:18px; height:18px; color:var(--primary,#2463eb); flex:0 0 auto; }
		.ttl { font-weight:600; font-size:14px; }
		.lbl { font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.03em;
			color:var(--muted-foreground,#71717a); margin-top:2px; }
		.muted { color:var(--muted-foreground,#71717a); margin:0; }
		.ok { color:var(--gh-ok-fg); font-weight:600; }
		.off { color:var(--gh-warn-fg); font-weight:600; }
		.more { margin-top:auto; color:var(--primary,#2463eb); font-weight:600; text-decoration:none; font-size:12px; }
		.more:hover { text-decoration:underline; }
		.signup { font-size:12px; } .signup a { color:var(--primary,#2463eb); font-weight:600; }
		.code { font-family:ui-monospace,Menlo,monospace; word-break:break-all;
			background:var(--muted,#f4f4f5); border:1px solid var(--border,#e4e4e7);
			border-radius:6px; padding:8px 10px; font-size:12px; }
		.err { color:var(--gh-bad-fg); background:color-mix(in oklab,var(--gh-bad) 10%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-bad) 30%,transparent); border-radius:6px; padding:8px 10px; }
		.warn { color:var(--gh-warn-fg); font-size:12px; }
		.btn { margin-top:auto; border:0; border-radius:8px; padding:10px; font-weight:600; font-size:13px;
			cursor:pointer; background:var(--primary,#2463eb); color:var(--primary-foreground,#fff); }
		.btn:hover:not(:disabled) { filter:brightness(1.05); }
		.btn:disabled { opacity:.6; cursor:default; }
		.btn.ghost { background:transparent; color:var(--foreground,#09090b);
			border:1px solid var(--border,#e4e4e7); margin-top:0; }`;
	}
}

/** The Guard page in Admin Next. */
function pageUrl() {
	return (window.__GRAV_ADMIN_BASE || '/admin').replace(/\/$/, '') + '/plugin/guard-helper';
}

// Coarse on purpose: "recently" vs "a while ago", not a precise figure.
function ago(unixSeconds) {
	if (!unixSeconds) return 'never';
	const secs = Math.max(0, Math.floor(Date.now() / 1000) - unixSeconds);
	if (secs < 90) return 'just now';
	const mins = Math.round(secs / 60);
	if (mins < 60) return `${mins} minutes ago`;
	const hours = Math.round(mins / 60);
	if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
	const days = Math.round(hours / 24);
	return `${days} day${days === 1 ? '' : 's'} ago`;
}

/** The host of a URL, for link text ("gravguard.com"); "Guard Cloud" when it has none. */
function host(url) {
	try { return new URL(url).host.replace(/^www\./i, '') || 'Guard Cloud'; } catch (_) { return 'Guard Cloud'; }
}

function esc(v) {
	return String(v ?? '').replace(/[&<>"']/g, (c) =>
		({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

customElements.define(TAG, GuardHelperWidget);
