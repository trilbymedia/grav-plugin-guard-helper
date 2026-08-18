# v0.2.0-beta.3
## 08/18/2026

1. [](#new)
    * The Guard page now shows which plugin and agent versions are running, and names the newer agent when one is available
    * Added a link straight through to your fleet in Guard Cloud
2. [](#improved)
    * The pairing code now appears directly beneath the button that generated it, instead of in a separate panel further up the page

# v0.2.0-beta.2
## 08/17/2026

1. [](#improved)
    * Declares which Grav generations it supports (1.7 and 2.0), so the admin and `bin/gpm index` stop guessing — a package with no compatibility data is displayed as 1.7 whatever it actually needs
    * Dependencies now name a real floor: Grav 1.7.51 and PHP 8.1

# v0.2.0-beta.1
## 08/17/2026

1. [](#new)
    * Guard page now leads with whether the control channel is working — a green or amber status line instead of a block of setup text, with cron kept collapsed as the advanced route
    * Free in-admin security checker, reading the public GravSec feed
    * Guard Shield: a signed request-filter that blocks hostile endpoint traffic
    * Agent setup works across both admins, with a dashboard widget and banner, browser-based install, and re-pairing
2. [](#improved)
    * Tells you when the site has no scheduled task, rather than silently never collecting queued work
    * Unpacked agent binaries are made executable, so installs on hosts with strict umasks work first time
3. [](#bugfix)
    * Fixed the plugin's own status route returning "No route matches" right after install
    * Push and cron check-ins are tracked separately, so an unauthenticated ping can no longer make delivery look healthy

# v0.1.0
## 06/13/2026

1. [](#new)
    * Browser-only Guard Agent installer — a super-admin setup page downloads, sha256-verifies, and unpacks the standalone agent into `<grav-root>/_guard`, then pairs it in-process (no shell, no crontab) and shows the pairing code
    * Admin2 integration — a "Guard" sidebar item, a component-mode plugin page, and a dashboard widget all drive the agent setup; backed by a `GuardController` API (`GET /guard-helper/status`, `POST /guard-helper/setup`), super-admin only
    * Prominent onboarding — a dismissible dashboard banner (with a shield icon) prompts setup until the agent is installed (uses the new `onApiDashboardNotifications` hook)
    * Admin classic support — a "Guard" admin-menu entry, a prominent top dashboard widget (classic admin has no banner primitive), plus a self-contained frontend fallback route that works even without an admin SPA
    * Scheduled task guidance — the setup page now shows whether the agent's per-minute check-in is running, and gives you the line to add when it isn't; that check-in is what picks up scheduled backups and updates
    * Signup call-to-action — every setup panel (Admin2 page + widget, classic widget, frontend page) points users without a Guard Cloud account to create a free one at gravguard.com, emphasized right after install when the pairing code is ready (`signup_url` config)
    * Phase 0 scaffold — plugin skeleton
