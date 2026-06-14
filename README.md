# Guard Helper Plugin

Optional companion plugin for [Grav Guard](https://gravguard.com). It adds the
pieces that genuinely need the Grav runtime.

## Browser-only agent setup (the primary on-ramp)

For users with no shell, this plugin **installs the standalone Guard Agent from
the browser**. A super-admin opens the setup route (default `/_guard-setup`) and
clicks one button; the plugin:

1. downloads the signed agent release from your Guard Cloud URL,
2. verifies its sha256 (refusing a tampered release),
3. unpacks it into `<grav-root>/_guard` (the reliably-writable spot on
   shared/managed hosts), and
4. generates the per-site key + starts a pairing window **in-process** — no
   `proc_open`, no crontab — then shows the pairing code to enter in Guard Cloud.

The CLI one-liner (`curl … | php`, the agent's `installer/gi.php`) remains the
secondary/advanced path; both produce the same in-webroot layout. The cloud URL
is set in plugin config (it's trust-critical) and never read from a request.

## Where setup shows up

The setup is discoverable on both admins:

- **Admin2** (the supported target for Grav 2) — a **Guard** entry in the
  sidebar opens the setup page (a web component), a **dashboard widget** shows
  agent status with a one-click install, and a **dismissible banner** (with a
  shield icon) prompts setup until the agent is installed. These are backed by a
  small API (`GET /guard-helper/status`, `POST /guard-helper/setup`),
  super-admin only.
- **Admin classic** — a **Guard** admin-menu entry, plus a prominent **top
  dashboard widget** (classic admin has no top-banner primitive, so the widget
  is the notice), plus a self-contained frontend fallback route (default
  `/_guard-setup`) that works even when no admin SPA is installed.

Every setup panel points users without a Guard Cloud account to create a free
one (`signup_url`, default `gravguard.com`) — emphasized right after install,
when the pairing code is ready to enter.

The banner relies on the API plugin's `onApiDashboardNotifications` event, which
lets a plugin raise a persistent, dismissible admin notice; the banner renders
the notice's `icon` as a Lucide icon when it's an icon name (added to
admin-next's `TopBanner`).

## Also in this plugin (Phase 3)

- Free in-admin health and vulnerability checker (Admin2 panel)
- One-click SSO login token consumption (off by default, scoped-key gated)
- Guard Shield request filtering (virtual patching)
- Save-event pings for real-time backup

The Guard **control channel never depends on this plugin** — fleet
management, updates, rescue, and backups are handled by the standalone Guard
Agent and keep working if this plugin (or Grav itself) is broken or absent.

## Requirements

- Grav 2.0+; PHP 8.1+ with ext-sodium and ext-zip (for the agent bootstrap)

## Development

```bash
composer install
composer test
```

Runtime ships zero Composer dependencies (Composer is dev/test only). The full
install/pair test runs against the sibling `agent/` checkout and skips when it
isn't present.
