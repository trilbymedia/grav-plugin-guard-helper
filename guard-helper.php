<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Guard Helper — the optional thin companion to Guard Agent.
 *
 * Only functionality that genuinely needs the Grav runtime lives here:
 * the in-admin health/vulnerability checker (Admin2), SSO token
 * consumption, Guard Shield request filtering, and save-event pings for
 * real-time backup. The Guard control channel NEVER depends on this
 * plugin; every fleet feature degrades gracefully without it.
 *
 * Phase 0 scaffold — event handlers land in Phase 3 (plan §6.4).
 */
class GuardHelperPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            // Phase 3: register the Admin2 health-checker panel.
            return;
        }

        // Phase 3: Guard Shield request filter + SSO token consumption.
    }
}
