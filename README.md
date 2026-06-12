# Guard Helper Plugin

Optional companion plugin for [Grav Guard](https://gravguard.com). It adds the
pieces that genuinely need the Grav runtime:

- Free in-admin health and vulnerability checker (Admin2 panel)
- One-click SSO login token consumption (off by default, scoped-key gated)
- Guard Shield request filtering (virtual patching)
- Save-event pings for real-time backup

The Guard **control channel never depends on this plugin** — fleet
management, updates, rescue, and backups are handled by the standalone Guard
Agent and keep working if this plugin (or Grav itself) is broken or absent.

## Status: Phase 0 scaffold

Event handlers and the Admin2 panel land in Phase 3. Grav 2.0 first; a 1.7
flavor only if beta demand shows up.

## Requirements

- Grav 2.0+
- Guard Agent installed and paired (the plugin talks to the agent locally,
  never to Guard Cloud directly)
