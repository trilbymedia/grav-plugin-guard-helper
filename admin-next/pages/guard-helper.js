// Guard Helper — plugin page (admin-next, component mode).
//
// The full browser-only Guard Agent setup, reached from the "Guard" sidebar
// item (/plugin/guard-helper). Shows install status + requirements and lets a
// super-admin download + verify + unpack + pair the standalone agent in one
// click, then displays the single-use pairing code to enter in Guard Cloud.
// Backed by GuardController (GET /guard-helper/status, /latest and /security,
// POST /guard-helper/setup, /repair and /webhook).
//
// Below the agent card sits the free vulnerability check: this site's
// installed packages against Guard Cloud's public advisory feed. The
// dashboard banner's "Review" opens this page at #security.
//
// The element tag is injected by admin-next as window.__GRAV_PAGE_TAG.
const TAG = window.__GRAV_PAGE_TAG;

class GuardHelperPage extends HTMLElement {
	constructor() {
		super();
		this.attachShadow({ mode: 'open' });
		this._state = {
			phase: 'loading', data: null, error: null, result: null, busy: false,
			security: { phase: 'loading', data: null, error: null, busy: false },
		};
		// The banner's "Review" links here as #security: scroll there once it has drawn.
		this._scrollToSecurity = window.location.hash === '#security';
	}

	connectedCallback() {
		this._render();
		this._loadStatus();
		this._loadSecurity(false);
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
			// The status call never asks Guard Cloud, so the page draws from
			// what the site already knows. When nothing fresh is stored about
			// the newest agent, ask now, after drawing, and fill it in.
			if (data?.agent_version && data.agent_latest_checked === false) this._loadLatest();
		} catch (e) {
			this._patch({ phase: 'error', error: e.message });
		}
	}

	async _loadLatest() {
		try {
			const latest = await this._api('GET', '/guard-helper/latest');
			this._patch({ data: { ...(this._state.data || {}), ...latest } });
		} catch (_) {
			// Leave it as "latest unknown": a version badge is not worth an error.
			this._patch({ data: { ...(this._state.data || {}), agent_latest_checked: true } });
		}
	}

	// The free check. The endpoint answers from a stored copy of the feed when
	// it has one; "Check again" asks Guard Cloud now (bounded, a few seconds).
	async _loadSecurity(refresh) {
		const prev = this._state.security;
		this._patch({ security: { ...prev, phase: prev.data ? 'ready' : 'loading', busy: true, error: null } });
		try {
			const data = await this._api('GET', '/guard-helper/security' + (refresh ? '?refresh=1' : ''));
			this._patch({ security: { phase: 'ready', data, error: null, busy: false } });
		} catch (e) {
			this._patch({ security: { phase: 'error', data: null, error: e.message, busy: false } });
		}
	}

	async _setup() {
		if (this._state.busy) return;
		const ok = window.__GRAV_DIALOGS
			? await window.__GRAV_DIALOGS.confirm({
				title: 'Set up Guard Agent',
				message: 'Download, verify, and install the Guard Agent into this site, then start pairing? '
					+ 'A signed release is fetched from your Guard Cloud URL, and its signature and every file are verified against the Guard release key before anything is written.',
				confirmLabel: 'Set up',
			})
			: true;
		if (!ok) return;

		this._patch({ busy: true, error: null });
		try {
			const result = await this._api('POST', '/guard-helper/setup', {});
			this._patch({ busy: false, result, data: { ...(this._state.data || {}), installed: true, webhook: result.webhook ?? null } });
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
			const data = result.webhook ? { ...(this._state.data || {}), webhook: result.webhook } : this._state.data;
			this._patch({ busy: false, result, data });
		} catch (e) {
			this._patch({ busy: false, error: e.message });
		}
	}

	async _enableWebhook() {
		if (this._state.busy) return;
		this._patch({ busy: true, webhookError: null });
		try {
			const webhook = await this._api('POST', '/guard-helper/webhook', {});
			this._patch({ busy: false, data: { ...(this._state.data || {}), webhook } });
		} catch (e) {
			this._patch({ busy: false, webhookError: e.message });
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
		this.shadowRoot.getElementById('webhook-btn')?.addEventListener('click', () => this._enableWebhook());
		this.shadowRoot.getElementById('sec-refresh')?.addEventListener('click', () => this._loadSecurity(true));
		this.shadowRoot.querySelectorAll('[data-copy]').forEach((el) =>
			el.addEventListener('click', () => this._copy(el.getAttribute('data-copy'))));

		if (this._scrollToSecurity && this._state.phase !== 'loading' && this._state.security.phase !== 'loading') {
			this._scrollToSecurity = false;
			// Not smooth: the page draws inside the host's own scroller, where a
			// smooth scroll from in here does nothing at all.
			this.shadowRoot.getElementById('security')?.scrollIntoView({ block: 'start' });
		}
	}

	_codeRow(label, value) {
		return `<div class="field">
			<div class="lbl">${esc(label)}</div>
			<div class="coderow">
				<code class="code">${esc(value)}</code>
				<button class="btn ghost sm" data-copy="${esc(value)}" title="Copy">Copy</button>
			</div></div>`;
	}

	// "Is Guard Cloud reaching this site?" is the only question worth putting in
	// front of someone here, and it is answerable: the agent stamps every
	// verified inbound command and every completed mailbox poll. Whether a
	// crontab exists is a detail of HOW work arrives, so it belongs behind a
	// disclosure, not in the reader's face.
	//
	// Deliberately not a freshness threshold. Pushes are work-driven, so an
	// idle site can legitimately go days without one, and "no contact in 24h"
	// would cry wolf on a perfectly healthy install.
	_delivery(s) {
		const d = s.data?.delivery;
		if (!d) return '';

		if (!d.reachable) {
			return `<div class="status-row warn-row">
				<span class="dot"></span>
				<div>
					<strong>Guard Cloud has not reached this site yet.</strong>
					<p class="hint">This is expected until you finish pairing. Once the site is added in
					Guard Cloud, work is delivered over HTTPS to the agent endpoint automatically — there
					is nothing to install.</p>
				</div>
			</div>`;
		}

		const seen = Math.max(d.last_tick_at || 0, d.last_push_at || 0);
		const how = d.channel === 'tick'
			? 'The agent is checking in on a schedule'
			: 'Guard Cloud is delivering work straight to this site';

		return `<div class="status-row ok-row">
			<span class="dot"></span>
			<div>
				<strong>Guard Cloud is reaching this site.</strong>
				<p class="hint">${how} — last contact ${esc(ago(seen))}. Scheduled backups, updates
				and offsite uploads are being delivered.</p>
			</div>
		</div>`;
	}

	// The advanced path, collapsed by default. Adding a cron makes work arrive
	// within a minute instead of within a few minutes; it is an optimisation,
	// not a requirement, and presenting it as a requirement is what made this
	// screen read as a wall of warnings.
	//
	// `known` still matters inside: most managed hosts disable the spawn
	// functions in the WEB pool, so from in here we usually cannot read the
	// crontab. Claiming a site with a perfectly good tick has none would be
	// worse than saying nothing.
	_cron(s) {
		const cron = s.data?.cron;
		if (!cron || cron.installed) return '';

		const line = cron.line || '';
		// Appends without opening an editor, which is the part people get stuck
		// on: `crontab -e` drops them into vi on most hosts. Reads the existing
		// crontab first so it adds to it rather than replacing it.
		const oneLiner = `( crontab -l 2>/dev/null; echo "${line}" ) | crontab -`;

		const caveat = cron.known
			? ''
			: `<p class="hint">This server does not let PHP read the crontab from the admin, so we
				cannot tell whether one is already set up. If you have added it, nothing here is needed.</p>`;

		return `<details class="adv">
			<summary>Run work every minute instead (optional)</summary>
			<div class="adv-body">
				<p class="hint">Work already arrives without this. A scheduled task just makes the agent
				collect it every minute rather than waiting for Guard Cloud to push it, which is a little
				faster and a little more efficient on large sites. It needs shell access.</p>
				${caveat}
				<div class="field">
					<div class="lbl">Run this once over SSH</div>
					<div class="coderow">
						<code class="code">${esc(oneLiner)}</code>
						<button class="btn ghost sm" data-copy="${esc(oneLiner)}" title="Copy">Copy</button>
					</div>
					<p class="hint">Adds to your existing crontab, it does not replace it. To add it by hand,
					or through your host's control panel, schedule this line every minute:</p>
					<div class="coderow">
						<code class="code">${esc(line)}</code>
						<button class="btn ghost sm" data-copy="${esc(line)}" title="Copy">Copy</button>
					</div>
				</div>
			</div>
		</details>`;
	}

	// Whether Guard Cloud can run this site's scheduled jobs. Most hosts don't
	// let the agent start Grav from a web request, so on those the only way
	// is Grav's scheduler webhook, which this plugin switches on when it
	// installs or re-pairs the agent. The token itself never reaches this page.
	_webhook(s) {
		const w = s.data?.webhook;
		if (!w) return '';

		const ready = w.state === 'ready';
		const title = ready
			? (w.changed ? "Scheduled jobs: Grav's scheduler webhook is now on." : 'Scheduled jobs: Guard Cloud can run them.')
			: (w.state === 'unverified'
				? "Scheduled jobs: set up, but couldn't confirm the agent reads it."
				: (['busy', 'write_conflict', 'lock_error'].includes(w.state)
					? "Scheduled jobs: setup didn't finish, nothing was changed."
					: 'Scheduled jobs: Guard Cloud may not be able to run them.'));
		// busy and write_conflict clear by trying again; lock_error once the
		// folder its message names is writable.
		const canFix = w.managed && ['off', 'mismatch', 'not_writable', 'busy', 'write_conflict', 'lock_error', 'error'].includes(w.state);
		const btn = canFix
			? `<button id="webhook-btn" class="btn ghost sm" ${s.busy ? 'disabled' : ''}>${s.busy ? 'Working…' : 'Turn on scheduler webhook'}</button>`
			: '';
		const err = s.webhookError ? `<div class="err">${esc(s.webhookError)}</div>` : '';

		return `<div class="status-row ${ready ? 'ok-row' : 'warn-row'}">
			<span class="dot"></span>
			<div class="row-body">
				<strong>${esc(title)}</strong>
				<p class="hint">${esc(w.message || '')}</p>
				${err}
				${btn}
			</div>
		</div>`;
	}

	/**
	 * What is actually running here. The agent version is the one people need
	 * when something misbehaves, and it was nowhere on this screen.
	 */
	_versions(s) {
		const d = s.data || {};
		const plugin = d.plugin_version ? `<span class="ver"><span class="ver-k">Plugin</span> ${esc(d.plugin_version)}</span>` : '';
		if (!d.agent_version) return plugin ? `<div class="vers">${plugin}</div>` : '';

		let agent = `<span class="ver"><span class="ver-k">Agent</span> ${esc(d.agent_version)}</span>`;
		if (d.agent_outdated === true) {
			// Say what to move TO, not just that it is behind — "out of date" with
			// no target is a worry rather than an instruction.
			agent += `<span class="ver-new">update available: ${esc(d.agent_latest)}</span>`;
		} else if (d.agent_latest_checked === false) {
			agent += `<span class="ver-unknown">checking for updates…</span>`;
		} else if (d.agent_outdated === null || d.agent_outdated === undefined) {
			// Could not ask the cloud. Not the same as being current.
			agent += `<span class="ver-unknown">latest unknown</span>`;
		}

		return `<div class="vers">${plugin}${agent}</div>`;
	}

	/** Button and code in one place — the code used to render far above it. */
	_pairing(s) {
		const btn = `<button id="repair-btn" class="btn ghost" ${s.busy ? 'disabled' : ''}>${s.busy ? 'Working…' : 'Show pairing code'}</button>`;
		if (!s.result) {
			return `<div class="pair-block">${btn}</div>`;
		}
		const ttlMin = Math.round((s.result.ttl || 0) / 60);
		return `<div class="pair-block">
			${btn}
			<div class="pair-out">
				<p class="muted">Enter this in <strong>Guard Cloud → Fleet → Add Site</strong> within ${ttlMin} minutes. Single-use; generate another if it expires.</p>
				${this._codeRow('Pairing code', s.result.code)}
			</div>
		</div>`;
	}

	/** Straight through to the fleet, so this page is not a dead end. */
	_manage(s) {
		const url = s.data?.manage_url;
		if (!url) return '';

		return `<p class="manage"><a href="${esc(url)}" target="_blank" rel="noopener">Manage this site in Guard Cloud →</a></p>`;
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
		return this._main(s) + this._security(s);
	}

	/**
	 * The vulnerability check: package, installed version, advisory, severity
	 * and fixed-in version for each finding, worst first. "Unknown" is its own
	 * state: when the feed can't be fetched nothing was checked, which is not
	 * the same as a clean site.
	 */
	_security(s) {
		const sec = s.security;
		const d = sec.data || {};
		const btn = `<button id="sec-refresh" class="btn ghost sm" ${sec.busy ? 'disabled' : ''}>${sec.busy ? 'Checking…' : 'Check again'}</button>`;
		const head = `<div class="sec-head"><h2>Vulnerability check</h2>${sec.phase === 'loading' ? '' : btn}</div>`;
		const checked = d.checked_at ? ` Checked ${esc(formatDate(d.checked_at))}.` : '';
		const pkgs = `${d.package_count} installed package${d.package_count === 1 ? '' : 's'}`;

		let body;
		if (sec.phase === 'loading') {
			body = `<p class="muted">Checking this site's packages against the Guard advisory feed…</p>`;
		} else if (sec.phase === 'error' || d.status !== 'ok') {
			const why = sec.phase === 'error' ? `<p class="hint">${esc(sec.error)}</p>` : '';
			body = `<div class="status-row warn-row"><span class="dot"></span><div>
				<strong>Unknown: the check could not reach Guard Cloud.</strong>
				<p class="hint">${sec.phase === 'error' ? 'This site\'s packages were not' : `None of this site's ${esc(pkgs)} were`} checked, so this is not a clean result.${d.checked_at ? ` Last tried ${esc(formatDate(d.checked_at))}.` : ''} It tries again within a couple of minutes, or use Check again.</p>
				${why}</div></div>`;
		} else if (!d.findings || d.findings.length === 0) {
			body = `<div class="status-row ok-row"><span class="dot"></span><div>
				<strong>No known vulnerabilities</strong>
				<p class="hint">${esc(pkgs)} checked against ${esc(d.advisory_count)} published advisor${d.advisory_count === 1 ? 'y' : 'ies'}.${checked}</p>
				</div></div>`;
		} else {
			const n = d.findings.length;
			body = `<div class="status-row bad-row"><span class="dot"></span><div>
				<strong>${n} known vulnerabilit${n === 1 ? 'y' : 'ies'} in this site's packages</strong>
				<p class="hint">Update each package to its fixed version.${checked}</p>
				</div></div>
				<div class="table-scroll"><table>
					<thead><tr><th>Package</th><th>Installed</th><th>Advisory</th><th>Severity</th><th>Fixed in</th></tr></thead>
					<tbody>${d.findings.map((f) => this._findingRow(f)).join('')}</tbody>
				</table></div>`;
		}

		return `<section class="card sec" id="security" aria-labelledby="sec-title">${head.replace('<h2>', '<h2 id="sec-title">')}${body}</section>`;
	}

	_findingRow(f) {
		const ref = (f.refs || []).find((r) => typeof r === 'string' && /^https:\/\//.test(r));
		const title = ref
			? `<a href="${esc(ref)}" target="_blank" rel="noopener">${esc(f.title || f.advisory_id)}</a>`
			: esc(f.title || f.advisory_id);
		const type = { core: 'Grav core', plugin: 'Plugin', theme: 'Theme' }[f.type] || f.type || '';
		const fixed = f.fixed_in ? `<span class="ver-cell">${esc(f.fixed_in)}</span>` : '<span class="muted">No fix yet</span>';
		return `<tr>
			<td><div class="pkg">${esc(f.package_name || f.slug)}</div>${f.type === 'core' ? '' : `<div class="sub">${esc(type)}</div>`}</td>
			<td class="ver-cell">${esc(f.installed_version)}</td>
			<td><div>${title}</div>${f.advisory_id ? `<div class="sub">${esc(f.advisory_id)}${f.cve ? ` · ${esc(f.cve)}` : ''}</div>` : ''}</td>
			<td>${severity(f.severity)}</td>
			<td>${fixed}</td>
		</tr>`;
	}

	_main(s) {
		if (s.phase === 'loading') {
			return `<div class="card"><div class="muted">Checking agent status…</div></div>`;
		}
		if (s.phase === 'error') {
			return `<div class="card"><div class="err">${esc(s.error)}</div>
				<button id="retry-btn" class="btn ghost">Retry</button></div>`;
		}

		const cloud = s.data?.cloud_url || '';

		// One card whether or not a code has just been generated. Two branches
		// meant the code rendered in a different place from the button that
		// produced it, which reads as though something else happened.
		if (s.data?.installed || s.result) {
			const justInstalled = !!s.result && !s.data?.installed;
			return `<div class="card">
				<div class="status ok"><span class="dot"></span> ${justInstalled ? 'Guard Agent installed — pairing started' : 'Guard Agent is active'}</div>
				${this._versions(s)}
				<p class="muted">Installed and ready. To add this site to Guard Cloud — or if the last code expired — generate a fresh pairing code (it's single-use).</p>
				${this._codeRow('Agent endpoint', s.data?.endpoint || s.result?.endpoint || s.data?.endpoint_path || '')}
				${this._delivery(s)}
				${this._webhook(s)}
				${this._cron(s)}
				${s.error ? `<div class="err">${esc(s.error)}</div>` : ''}
				${this._pairing(s)}
				${this._manage(s)}
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
			<p class="muted">This installs the standalone Guard Agent into <code>_guard/</code> and starts pairing — no shell needed. A signed release is downloaded from <code>${esc(cloud)}</code>, and its signature and every file are verified against the Guard release key before anything is written. Afterwards you'll be shown a scheduled task to add, if your host supports one.</p>
			${s.error ? `<div class="err">${esc(s.error)}</div>` : ''}
			${warn}
			<button id="setup-btn" class="btn" ${s.busy || reqBad ? 'disabled' : ''}>${s.busy ? 'Setting up…' : 'Set up Guard Agent'}</button>
			${this._signup(s)}
		</div>`;
	}

	_css() {
		return `
		:host { display:block;
			/* The host's semantic colours, with a fallback for an older host.
			   Text shades mix toward --foreground so they read in both themes. */
			--gh-ok: var(--success, #16a34a);
			--gh-warn: var(--warning, #d97706);
			--gh-bad: var(--destructive, #dc2626);
			--gh-ok-fg: color-mix(in oklab, var(--gh-ok) 74%, var(--foreground, #09090b));
			--gh-warn-fg: color-mix(in oklab, var(--gh-warn) 62%, var(--foreground, #09090b));
			/* 55%, not 62%: older hosts define a dark-theme --destructive that
			   is nearly as dark as the card behind it. */
			--gh-bad-fg: color-mix(in oklab, var(--gh-bad) 55%, var(--foreground, #09090b)); }
		.wrap { max-width:640px; display:flex; flex-direction:column; gap:16px; }
		.card { display:flex; flex-direction:column; gap:14px;
			padding:24px; border:1px solid var(--border,#e4e4e7); border-radius:12px;
			background:var(--card,var(--background,#fff)); color:var(--foreground,#09090b);
			font:14px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
		.status { display:flex; align-items:center; gap:8px; font-weight:600; font-size:15px; }
		.dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
		.ok .dot { background:var(--gh-ok); } .ok { color:var(--gh-ok-fg); }
		.off .dot { background:var(--gh-warn); } .off { color:var(--gh-warn-fg); }
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
		.err { color:var(--gh-bad-fg); background:color-mix(in oklab,var(--gh-bad) 10%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-bad) 30%,transparent); border-radius:8px; padding:10px 12px; }
		.warn { color:var(--gh-warn-fg); font-size:13px; }
		/* The cron notice carries a copyable line, so it needs to read as a
		   panel rather than a stray sentence. */
		.warn.panel { display:flex; flex-direction:column; gap:10px;
			background:color-mix(in oklab,var(--gh-warn) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-warn) 28%,transparent);
			border-radius:8px; padding:12px 14px; }
		.warn.panel strong { color:var(--foreground,#09090b); }
		/* Explanatory text: guidance rather than warning, so it reads in the
		   normal muted colour wherever it appears. */
		.hint { color:var(--muted-foreground,#71717a); font-size:13px;
			margin:0; line-height:1.5; }
		/* The headline answer — a single line with a coloured dot, so the state
		   is readable at a glance instead of having to be inferred from prose. */
		.status-row { display:flex; gap:10px; align-items:flex-start; margin-top:18px;
			padding:12px 14px; border-radius:8px; }
		.status-row .dot { flex:none; margin-top:6px; }
		.status-row strong { display:block; font-size:14px; margin-bottom:2px; }
		.status-row .row-body { display:flex; flex-direction:column; gap:8px; }
		.status-row .row-body strong { margin-bottom:-6px; }
		/* Two status rows in a row: the gap between them comes from the card. */
		.status-row + .status-row { margin-top:0; }
		.ok-row { background:color-mix(in oklab,var(--gh-ok) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-ok) 26%,transparent); }
		.ok-row .dot { background:var(--gh-ok); }
		.warn-row { background:color-mix(in oklab,var(--gh-warn) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-warn) 28%,transparent); }
		.warn-row .dot { background:var(--gh-warn); }
		.bad-row { background:color-mix(in oklab,var(--gh-bad) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--gh-bad) 28%,transparent); }
		.bad-row .dot { background:color-mix(in oklab,var(--gh-bad) 75%,var(--foreground,#09090b)); }
		/* Collapsed by default: this is the optional, shell-only route, and
		   leaving it open turned the page into a wall of instructions. */
		.adv { margin-top:14px; border:1px solid var(--border,#e4e4e7); border-radius:8px; }
		.adv > summary { cursor:pointer; padding:10px 14px; font-size:13px; font-weight:600;
			color:var(--muted-foreground,#71717a); list-style:none; }
		.adv > summary::-webkit-details-marker { display:none; }
		.adv > summary::before { content:'▸'; display:inline-block; width:1em; }
		.adv[open] > summary::before { content:'▾'; }
		.adv > summary:hover { color:var(--foreground,#09090b); }
		.adv-body { padding:0 14px 14px; display:flex; flex-direction:column; gap:10px;
			border-top:1px solid var(--border,#e4e4e7); padding-top:12px; }
		.btn { align-self:flex-start; border:0; border-radius:8px; padding:11px 18px; font-weight:600; font-size:14px;
			cursor:pointer; background:var(--primary,#2463eb); color:var(--primary-foreground,#fff); }
		.btn:hover:not(:disabled) { filter:brightness(1.05); }
		.btn:disabled { opacity:.6; cursor:default; }
		.btn.ghost { background:transparent; color:var(--foreground,#09090b); border:1px solid var(--border,#e4e4e7); }
		.btn.sm { padding:6px 12px; font-size:12px; }
		.vers { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:-4px 0 12px; }
		.ver { font-size:12px; color:var(--muted-foreground,#71717a); }
		.ver-k { text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-right:4px; }
		.ver-new { font-size:12px; font-weight:600; color:var(--gh-warn-fg);
			background:color-mix(in srgb,var(--gh-warn) 14%,transparent); padding:2px 8px; border-radius:99px; }
		.ver-unknown { font-size:12px; color:var(--muted-foreground,#71717a); font-style:italic; }
		.pair-block { margin-top:14px; }
		.pair-out { margin-top:12px; padding:12px 14px; border-radius:8px;
			border:1px solid var(--border,#e4e4e7); background:var(--muted-bg,rgba(0,0,0,.02)); }
		.pair-out .muted { margin-top:0; }
		.manage { margin:14px 0 0; font-size:13px; }
		.manage a { color:var(--primary,#2463eb); font-weight:600; text-decoration:none; }
		.manage a:hover { text-decoration:underline; }
		.signup { padding:12px 14px; border-radius:8px; font-size:13px;
			background:color-mix(in oklab,var(--primary,#2463eb) 8%,transparent);
			border:1px solid color-mix(in oklab,var(--primary,#2463eb) 25%,transparent); }
		.signup a, .signup-foot a { color:var(--primary,#2463eb); font-weight:600; }
		.signup-foot { padding-top:14px; border-top:1px solid var(--border,#e4e4e7); }
		/* Vulnerability check */
		.sec { gap:12px; }
		.sec-head { display:flex; align-items:center; justify-content:space-between; gap:12px; }
		.sec-head h2 { margin:0; font-size:15px; font-weight:600; }
		.sec .status-row { margin-top:0; }
		.table-scroll { overflow-x:auto; }
		table { inline-size:100%; border-collapse:collapse; font-size:13px; }
		th { text-align:start; color:var(--muted-foreground,#71717a); font-weight:500; font-size:12px;
			padding:8px 10px; border-block-end:1px solid var(--border,#e4e4e7); white-space:nowrap;
			background:color-mix(in srgb,var(--muted,#f4f4f5) 30%,transparent); }
		td { padding:8px 10px; vertical-align:top;
			border-block-end:1px solid color-mix(in srgb,var(--border,#e4e4e7) 60%,transparent); }
		tbody tr:last-child td { border-block-end:0; }
		td a { color:var(--primary,#2463eb); text-decoration:none; }
		td a:hover { text-decoration:underline; }
		.pkg { font-weight:600; white-space:nowrap; }
		.sub { color:var(--muted-foreground,#71717a); font-size:12px; }
		.ver-cell { font-family:ui-monospace,Menlo,monospace; font-size:12px; white-space:nowrap; font-variant-numeric:tabular-nums; }
		.pill { display:inline-block; border-radius:6px; padding:1px 8px; font-size:0.6875rem; font-weight:500; white-space:nowrap; }
		.pill.bad { background:color-mix(in srgb,var(--gh-bad) 16%,transparent); color:var(--gh-bad-fg); }
		.pill.warn { background:color-mix(in srgb,var(--gh-warn) 18%,transparent); color:var(--gh-warn-fg); }
		.pill.muted { background:var(--muted,#f4f4f5); color:var(--muted-foreground,#71717a); }`;
	}
}

// One table from a feed severity to a pill: the words people read, in the
// host's tones. A severity nobody has named yet still shows, as itself.
const SEVERITY = {
	critical: ['bad', 'Critical'], high: ['bad', 'High'],
	medium: ['warn', 'Medium'], moderate: ['warn', 'Moderate'], low: ['muted', 'Low'],
};
function severity(value) {
	const key = String(value || '').toLowerCase();
	const [tone, label] = SEVERITY[key] || ['muted', key ? key[0].toUpperCase() + key.slice(1) : 'Unrated'];
	return `<span class="pill ${tone}">${esc(label)}</span>`;
}

const DATE_TIME = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
function formatDate(iso) {
	const d = new Date(iso);
	return Number.isNaN(d.getTime()) ? '' : DATE_TIME.format(d);
}

function esc(v) {
	return String(v ?? '').replace(/[&<>"']/g, (c) =>
		({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Coarse on purpose: the point is "recently" vs "a while ago", and a precise
// figure would invite reading significance into normal variation.
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

customElements.define(TAG, GuardHelperPage);
