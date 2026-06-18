<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\BootstrapException;
use RocketTheme\Toolbox\Event\Event;

/**
 * Guard Helper — the optional thin companion to Guard Agent.
 *
 * Headline job: the BROWSER-ONLY agent installer. A super-admin clicks one
 * button and the plugin downloads + verifies + unpacks the standalone agent
 * into <grav-root>/_guard and pairs it in-process — no shell, no crontab. The
 * CLI one-liner (agent installer/gi.php) is the secondary/advanced path.
 *
 * The setup UI is discoverable on BOTH admins:
 *   • Admin2 (the supported target for Grav 2) — a sidebar item + a component
 *     plugin page + a dashboard widget + a dismissible "set up Guard" banner,
 *     all backed by the API plugin (see GuardController).
 *   • Admin classic — an admin-menu entry, plus a self-contained frontend
 *     fallback route (works even when an admin SPA isn't installed).
 *
 * The Guard control channel NEVER depends on this plugin; every fleet feature
 * degrades gracefully without it.
 */
class GuardHelperPlugin extends Plugin
{
    private const NONCE_ACTION = 'guard-helper-setup';
    private const SLUG = 'guard-helper';

    public static function getSubscribedEvents(): array
    {
        return [
            // autoload first so our classes resolve in the API request path too
            // (the API plugin instantiates GuardController via the route map).
            'onPluginsInitialized'        => [['autoload', 100001], ['onPluginsInitialized', 0]],

            // Admin classic.
            'onAdminMenu'                 => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths'    => ['onAdminTwigTemplatePaths', 0],
            // Low priority: let core admin add its widgets first so we can slot
            // ours in right after the statistics widget.
            'onAdminDashboard'            => ['onAdminDashboard', -100],

            // Admin2 / API. These MUST be registered unconditionally — never
            // gate them on isAdmin() in onPluginsInitialized, because the API's
            // admin proxy only registers mid-dispatch, after that point.
            'onApiRegisterRoutes'         => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'           => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo'         => ['onApiPluginPageInfo', 0],
            'onApiDashboardWidgets'       => ['onApiDashboardWidgets', 0],
            'onApiDashboardNotifications' => ['onApiDashboardNotifications', 0],
        ];
    }

    /** Zero-dep PSR-4 autoloader for our own classes (no composer at runtime). */
    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GuardHelper\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        // Guard Shield runs first — an endpoint-WAF pass that may block the
        // request before any further work. Cheap (cached rules) and fail-open.
        $this->evaluateShield();

        // The self-contained setup page. It serves BOTH the frontend fallback
        // route and the classic admin-menu route (handleSetup renders its own
        // HTML and exits, so it works in either context).
        $path = rtrim($this->grav['uri']->path(), '/');
        if ($path === $this->frontendRoute() || $path === $this->adminRoute()) {
            // Defer to onPagesInitialized so the login plugin has authenticated
            // the user by the time we check permissions.
            $this->enable(['onPagesInitialized' => ['handleSetup', 0]]);
        }
    }

    /**
     * Guard Shield request filter. Fetches the signature-verified rule bundle
     * (cached) and blocks or logs the request if a rule matches. Wrapped so a
     * Shield error can never take the site down — it fails open.
     */
    private function evaluateShield(): void
    {
        if (\PHP_SAPI === 'cli' || !$this->config->get('plugins.guard-helper.shield.enabled', false)) {
            return;
        }
        $publicKey = (string) $this->config->get('plugins.guard-helper.shield.public_key', '');
        if ($publicKey === '') {
            return;
        }

        try {
            $url = rtrim($this->cloudUrl(), '/') . '/shield/rules.json';
            $rules = (new \Grav\Plugin\GuardHelper\Shield\ShieldRules($this->grav))->rules($url, $publicKey);
            if ($rules === []) {
                return;
            }

            $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $path = (string) $this->grav['uri']->path();
            $params = array_merge($_GET, $_POST);

            $hit = \Grav\Plugin\GuardHelper\Shield\ShieldFilter::match($method, $path, $params, $rules);
            if ($hit === null) {
                return;
            }

            $ruleId = (string) ($hit['rule_id'] ?? 'unknown');
            if (($hit['action'] ?? 'block') === 'log') {
                $this->grav['log']->warning("Guard Shield matched (log) {$ruleId}: {$method} {$path}");

                return;
            }

            $this->grav['log']->warning("Guard Shield blocked {$ruleId}: {$method} {$path}");
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Guard-Shield: blocked');
            echo 'Forbidden';
            exit;
        } catch (\Throwable) {
            // Shield must never break the site on its own failure — fail open.
        }
    }

    // ── Admin classic ──────────────────────────────────────────────────────

    /** Add a "Guard" entry to the classic admin sidebar (super-admin only). */
    public function onAdminMenu(): void
    {
        $this->grav['twig']->plugins_hooked_nav['Guard'] = [
            'route'     => self::SLUG,
            'icon'      => 'fa-shield-halved',
            'authorize' => 'admin.super',
        ];
    }

    /** Make our dashboard-widget template resolvable in classic admin. */
    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'] ?? [];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    /**
     * Classic admin has no top-banner primitive, so the prominent setup notice
     * IS a top dashboard widget. It only renders if widgets_display opts the
     * template in (ours isn't in the admin defaults), so enable it at runtime.
     *
     * Runs at a low priority (see getSubscribedEvents) so the core admin has
     * already populated the widget list — that lets us slot ours in right after
     * the page-view statistics widget instead of dangling at the end.
     */
    public function onAdminDashboard(): void
    {
        $user = $this->grav['user'] ?? null;
        if ($user === null || !$user->authorize('admin.super')) {
            return;
        }

        $this->grav['config']->set('plugins.admin.widgets_display.dashboard-guard', true);

        $boot = $this->bootstrap();
        $twig = $this->grav['twig'];
        $twig->twig_vars['guard_installed'] = $boot->isInstalled();
        $twig->twig_vars['guard_endpoint'] = $this->absoluteUrl($boot->endpointPath());
        $twig->twig_vars['guard_setup_url'] = $this->absoluteUrl($this->frontendRoute());
        $twig->twig_vars['guard_signup_url'] = $this->signupUrl();

        $widget = ['name' => 'Grav Guard', 'template' => 'dashboard-guard'];
        $widgets = $twig->plugins_hooked_dashboard_widgets_top ?? [];

        // Insert immediately after the page-view statistics widget; fall back to
        // appending if the core widget isn't present.
        $at = null;
        foreach ($widgets as $i => $w) {
            if (($w['template'] ?? null) === 'dashboard-statistics') {
                $at = $i + 1;
                break;
            }
        }
        if ($at === null) {
            $widgets[] = $widget;
        } else {
            array_splice($widgets, $at, 0, [$widget]);
        }

        $twig->plugins_hooked_dashboard_widgets_top = $widgets;
    }

    // ── Admin2 / API ───────────────────────────────────────────────────────

    /** Register the backend the sidebar page + dashboard widget call. */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\GuardHelper\Api\GuardController::class;

        $routes->get('/' . self::SLUG . '/status', [$controller, 'status']);
        $routes->post('/' . self::SLUG . '/setup', [$controller, 'setup']);
        $routes->post('/' . self::SLUG . '/repair', [$controller, 'repair']);
        $routes->get('/' . self::SLUG . '/security', [$controller, 'security']);
    }

    /** A "Guard" item in the admin-next sidebar → /plugin/guard-helper. */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => self::SLUG,
            'plugin'    => self::SLUG,
            'label'     => 'Guard',
            'icon'      => 'fa-shield-halved',
            'route'     => '/plugin/' . self::SLUG,
            'priority'  => 5,
            'authorize' => 'admin.super',
        ];
        $event['items'] = $items;
    }

    /** Render the setup UI as a component-mode plugin page (admin-next/pages/). */
    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== self::SLUG) {
            return;
        }
        $event['definition'] = [
            'id'        => self::SLUG,
            'plugin'    => self::SLUG,
            'title'     => 'Guard Agent',
            'icon'      => 'fa-shield-halved',
            'page_type' => 'component',
        ];
    }

    /** A prominent dashboard card (super-admin only). */
    public function onApiDashboardWidgets(Event $event): void
    {
        if (!$this->eventUserIsSuperAdmin($event)) {
            return;
        }
        $widgets = $event['widgets'] ?? [];
        $widgets[] = [
            'id'           => self::SLUG . '.setup',
            'plugin'       => self::SLUG,
            'label'        => 'Guard Agent',
            'icon'         => 'ShieldCheck', // Lucide (dashboard widgets use Lucide)
            'sizes'        => ['sm', 'md'],
            'defaultSize'  => 'sm',
            'priority'     => 80,
            'authorize'    => 'api.system.read',
            'scriptUrl'    => '/gpm/plugins/' . self::SLUG . '/widget-script',
            'dataEndpoint' => '/' . self::SLUG . '/status',
        ];
        $event['widgets'] = $widgets;
    }

    /** A dismissible top banner until the agent is set up (super-admin only). */
    public function onApiDashboardNotifications(Event $event): void
    {
        if (!$this->eventUserIsSuperAdmin($event)) {
            return;
        }

        $notifications = $event['notifications'] ?? [];

        // Setup nudge — shown only until the agent is installed.
        if (!$this->bootstrap()->isInstalled()) {
            $notifications['top'][] = [
                'id'             => 'guard-helper-setup', // dismissed via the standard hide endpoint
                'title'          => 'Grav Guard',
                'message'        => 'The Guard Agent is not set up yet — install it to enable fleet updates, backups, and monitoring.',
                'icon'           => 'ShieldCheck', // Lucide name; TopBanner renders it as an icon

                'reappear_after' => '+1 week',
                'action'         => [
                    'label' => 'Set up',
                    'url'   => $this->absoluteUrl($this->frontendRoute()),
                ],
            ];
        }

        // Free GravSec check — independent of the agent; never breaks the dashboard.
        try {
            $feedUrl = rtrim($this->cloudUrl(), '/') . '/feed/advisories.json';
            $result = (new \Grav\Plugin\GuardHelper\Security\SecurityChecker($this->grav))->run($feedUrl);
            $count = count($result['findings']);
            if ($count > 0) {
                $notifications['top'][] = [
                    'id'             => 'guard-helper-vulns',
                    'title'          => 'Security advisories',
                    'message'        => sprintf(
                        '%d known %s on this site\'s installed packages.',
                        $count,
                        $count === 1 ? 'vulnerability' : 'vulnerabilities'
                    ),
                    'icon'           => 'ShieldAlert',
                    'reappear_after' => '+1 day',
                    'action'         => ['label' => 'Review', 'url' => rtrim($this->cloudUrl(), '/')],
                ];
            }
        } catch (\Throwable) {
            // A failed check must never block the dashboard.
        }

        $event['notifications'] = $notifications;
    }

    // ── Classic / fallback setup page ──────────────────────────────────────

    /** Serve the setup page (GET = status/form, POST = run the bootstrap). */
    public function handleSetup(): void
    {
        $user = $this->grav['user'] ?? null;
        if ($user === null || !$user->authenticated || !$user->authorize('admin.super')) {
            $this->grav->redirect('/admin', 302);

            return;
        }

        $cloud = $this->cloudUrl();
        $boot = $this->bootstrap();

        $result = null;
        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Utils::verifyNonce($_POST['guard-nonce'] ?? '', self::NONCE_ACTION)) {
                $error = 'Security token expired — please try again.';
            } else {
                try {
                    // `repair` starts a fresh pairing window on an installed
                    // agent; otherwise this is a first-time install.
                    $result = (($_POST['guard-action'] ?? '') === 'repair')
                        ? $boot->repair()
                        : $boot->install($cloud);
                } catch (BootstrapException $e) {
                    $error = $e->getMessage();
                } catch (\Throwable $e) {
                    $this->grav['log']->error('Guard Helper setup failed: ' . $e->getMessage(), ['exception' => $e]);
                    $error = 'Setup failed unexpectedly: ' . $e->getMessage();
                }
            }
        }

        $this->renderAndExit($this->page($boot, $cloud, $result, $error));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function bootstrap(): AgentBootstrap
    {
        return new AgentBootstrap(GRAV_ROOT);
    }

    private function cloudUrl(): string
    {
        return (string) $this->config->get('plugins.guard-helper.cloud_url', 'https://gravguard.com');
    }

    private function signupUrl(): string
    {
        return (string) $this->config->get('plugins.guard-helper.signup_url', 'https://gravguard.com');
    }

    private function frontendRoute(): string
    {
        return rtrim('/' . trim((string) $this->config->get('plugins.guard-helper.setup_route', '/_guard-setup'), '/'), '/');
    }

    private function adminRoute(): string
    {
        $base = rtrim((string) $this->config->get('plugins.admin.route', '/admin'), '/');

        return $base . '/' . self::SLUG;
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) $this->grav['uri']->rootUrl(true), '/') . $path;
    }

    private function eventUserIsSuperAdmin(Event $event): bool
    {
        $user = $event['user'] ?? null;

        return $user !== null && (bool) $user->get('access.admin.super', false);
    }

    /** Build the self-contained HTML page. */
    private function page(AgentBootstrap $boot, string $cloud, ?array $result, ?string $error): string
    {
        $e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $endpoint = $this->absoluteUrl($result['endpoint_path'] ?? $boot->endpointPath());
        $signupUrl = $this->signupUrl();

        if ($result !== null) {
            $body = '
                <div class="ok">Guard Agent installed.</div>
                <p>Enter these in <strong>Guard Cloud → Fleet → Add Site</strong> within ' . (int) ($result['ttl'] / 60) . ' minutes:</p>
                <label>Pairing code</label>
                <div class="code">' . $e($result['code']) . '</div>
                <label>Agent endpoint</label>
                <div class="code">' . $e($endpoint) . '</div>
                <p class="muted">The code is single-use. If it expires, reload this page to start a new pairing window.</p>';
        } elseif ($boot->isInstalled()) {
            $body = '
                <div class="ok">The Guard Agent is installed.</div>
                <label>Agent endpoint</label>
                <div class="code">' . $e($endpoint) . '</div>
                <p class="muted">To add this site to Guard Cloud — or if the last code expired — generate a fresh pairing code (it is single-use).</p>
                <form method="post">
                    <input type="hidden" name="guard-nonce" value="' . $e(Utils::getNonce(self::NONCE_ACTION)) . '">
                    <input type="hidden" name="guard-action" value="repair">
                    <button type="submit">Show pairing code</button>
                </form>';
        } else {
            $errHtml = $error !== null ? '<div class="err">' . $e($error) . '</div>' : '';

            // ext-sodium / ext-zip ship with PHP and are on virtually every
            // host — only warn (and block) if this server is actually missing one.
            $missing = [];
            if (!extension_loaded('sodium')) {
                $missing[] = 'ext-sodium';
            }
            if (!extension_loaded('zip')) {
                $missing[] = 'ext-zip';
            }
            $warn = $missing === [] ? '' : '<div class="err">This server is missing '
                . $e(implode(' and ', $missing)) . '. Ask your host to enable '
                . (count($missing) > 1 ? 'them' : 'it') . ' before setting up the agent.</div>';
            $disabled = $missing === [] ? '' : ' disabled';

            $body = $errHtml . '
                <p>This installs the standalone Guard Agent into <code>' . $e(basename(GRAV_ROOT)) . '/_guard</code>
                and starts pairing — no shell needed. It downloads a signed release from
                <code>' . $e($cloud) . '</code> and verifies it before installing.</p>'
                . $warn . '
                <form method="post">
                    <input type="hidden" name="guard-nonce" value="' . $e(Utils::getNonce(self::NONCE_ACTION)) . '">
                    <button type="submit"' . $disabled . '>Set up Guard Agent</button>
                </form>';
        }

        // After install the user needs a Guard Cloud account to enter the
        // pairing code — make that the obvious next step; keep it a quiet
        // footer otherwise.
        if ($result !== null) {
            $signup = '<div class="signup"><strong>Next:</strong> enter the code in Guard Cloud.
                No account yet? <a href="' . $e($signupUrl) . '" target="_blank" rel="noopener">Create a free account at gravguard.com</a>.</div>';
        } else {
            $signup = '<p class="muted signup-foot">No Guard Cloud account yet?
                <a href="' . $e($signupUrl) . '" target="_blank" rel="noopener">Sign up free at gravguard.com</a>.</p>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up Guard Agent</title>
<style>
  :root{--bg:#fff;--fg:#09090b;--muted:#71717a;--border:#e4e4e7;--primary:#2463eb;--ok:#00bb7f;--err:#dc2626;}
  *{box-sizing:border-box}
  body{margin:0;background:#f4f4f5;color:var(--fg);font:15px/1.6 "Google Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  .wrap{max-width:520px;margin:8vh auto 0;padding:0 16px}
  .brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
  .mark{width:30px;height:30px;border-radius:7px;background:var(--primary);color:#fff;display:grid;place-items:center;font-weight:700}
  .card{background:var(--bg);border:1px solid var(--border);border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:28px}
  h1{font-size:20px;margin:0 0 6px}
  label{display:block;font-weight:600;font-size:13px;margin:16px 0 4px}
  .code{font-family:ui-monospace,Menlo,monospace;background:#f4f4f5;border:1px solid var(--border);border-radius:6px;padding:10px 12px;word-break:break-all}
  code{font-family:ui-monospace,Menlo,monospace;background:#f4f4f5;padding:1px 5px;border-radius:4px;font-size:.92em}
  button{margin-top:16px;width:100%;background:var(--primary);color:#fff;border:0;border-radius:6px;padding:12px;font-size:15px;font-weight:600;cursor:pointer}
  button:hover:not(:disabled){background:#1f57d6}
  button:disabled{opacity:.6;cursor:default}
  .muted{color:var(--muted);font-size:13px}
  .ok{color:var(--ok);font-weight:600;margin-bottom:8px}
  .err{background:#fceeee;color:var(--err);border:1px solid #f5c2bd;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:14px}
  .signup{margin-top:18px;padding:12px 14px;background:color-mix(in oklab,var(--primary) 8%,transparent);border:1px solid color-mix(in oklab,var(--primary) 25%,transparent);border-radius:8px;font-size:14px}
  .signup a,.signup-foot a{color:var(--primary);font-weight:600}
  .signup-foot{margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
</style></head><body>
  <div class="wrap">
    <div class="brand"><span class="mark">G</span><strong>Grav Guard</strong></div>
    <div class="card"><h1>Set up Guard Agent</h1>' . $body . $signup . '</div>
  </div>
</body></html>';
    }

    private function renderAndExit(string $html): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }
}
