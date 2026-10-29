<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\BootstrapException;
use Grav\Plugin\GuardHelper\LookupCache;
use Grav\Plugin\GuardHelper\SchedulerWebhook;
use Grav\Plugin\GuardHelper\Security\SecurityChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 (API) backend for the browser-only Guard Agent setup.
 *
 * Endpoints behind the admin-next sidebar page + dashboard widget:
 *   GET  /guard-helper/status  — is the agent installed, and where? (local only)
 *   GET  /guard-helper/latest  — the newest agent version, asked of Guard Cloud
 *   POST /guard-helper/setup   — download + verify + unpack + pair in-process
 *   POST /guard-helper/repair  — a fresh pairing code for an installed agent
 *   POST /guard-helper/webhook — switch on Grav's scheduler webhook for the agent
 *
 * All are super-admin only: installing software and minting a per-site key is
 * not a delegate-able action. The trust-critical cloud URL comes from plugin
 * config, never the request body — the same rule the classic setup page follows.
 */
class GuardController extends AbstractApiController
{
    /** GET /guard-helper/status */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $boot = $this->bootstrap();

        $cloudUrl = $this->cloudUrl();
        $agentVersion = $boot->agentVersion();
        // Never asks Guard Cloud: this answers from what is on disk so the
        // page draws at once. When no fresh answer is stored the page asks
        // /latest after it has drawn. Only relevant once an agent is installed.
        $latest = $agentVersion === null
            ? ['checked' => true, 'version' => null, 'checked_at' => null]
            : $boot->knownLatestAgentVersion($cloudUrl, $this->lookupCache());
        $latestAgent = $latest['version'];

        return ApiResponse::create([
            'installed'     => $boot->isInstalled(),
            'endpoint_path' => $boot->endpointPath(),
            'endpoint'      => $this->absoluteEndpoint($boot->endpointPath()),
            'cloud_url'     => $cloudUrl,
            // Where to go to actually manage any of this.
            'manage_url'    => rtrim($cloudUrl, '/') . '/app/fleet',
            'signup_url'    => $this->signupUrl(),
            'plugin_version' => (string) $this->config->get('plugins.guard-helper.__version', '')
                ?: $this->pluginVersion(),
            'agent_version' => $agentVersion,
            // Null means we could not ask, NOT that the agent is current — the
            // UI has to say "unknown" rather than imply everything is fine.
            'agent_latest'  => $latestAgent,
            'agent_outdated' => self::outdated($agentVersion, $latestAgent),
            // False when nothing fresh is stored: the page asks /latest.
            'agent_latest_checked' => $latest['checked'],
            'requirements'  => [
                'sodium' => extension_loaded('sodium'),
                'zip'    => extension_loaded('zip'),
            ],
            // Installing in the browser needs no crontab, but RUNNING still
            // wants one: the tick is the only thing that collects queued work.
            // Null means we cannot tell yet (nothing unpacked, or an agent
            // older than the probe) — the UI stays quiet rather than guessing.
            'cron'          => $boot->cronStatus(),
            // The headline: is Guard Cloud actually reaching this site? That
            // is what the screen leads with; cron is one way of achieving it,
            // not the question itself.
            'delivery'      => $boot->deliveryStatus(),
            // Whether Guard Cloud can run this site's scheduled jobs on a host
            // where the agent cannot start Grav. Only asked once there is an
            // agent to use it. Never carries the token.
            'webhook'       => $boot->isInstalled() ? $this->webhookStatus() : null,
        ]);
    }

    /**
     * GET /guard-helper/latest — the newest agent version Guard Cloud offers.
     * Asks only when nothing fresh is stored, with a short timeout, and
     * stores the answer (or the failure, briefly) for the status call.
     */
    public function latest(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $boot = $this->bootstrap();
        $agentVersion = $boot->agentVersion();
        $latest = $agentVersion === null
            ? ['checked' => true, 'version' => null, 'checked_at' => null]
            : $boot->checkLatestAgentVersion($this->cloudUrl(), $this->lookupCache());

        return ApiResponse::create([
            'agent_version' => $agentVersion,
            'agent_latest' => $latest['version'],
            'agent_outdated' => self::outdated($agentVersion, $latest['version']),
            'agent_latest_checked' => true,
        ]);
    }

    /** Null when either side is unknown: "could not ask" is not "current". */
    private static function outdated(?string $running, ?string $latest): ?bool
    {
        return ($running !== null && $latest !== null) ? version_compare($running, $latest, '<') : null;
    }

    /** POST /guard-helper/setup */
    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $boot = $this->bootstrap();

        try {
            // The site's own base URL, as Grav sees it, is pinned for the
            // agent's self-calls so no signed command can redirect them.
            $result = $boot->install($this->cloudUrl(), $this->siteRoot());
            $result['webhook'] = $this->ensureWebhook();
        } catch (BootstrapException $e) {
            // Operational failure (unreachable cloud, tampered release, already
            // installed, non-writable root) — a 422 with the human message the
            // setup UI shows verbatim.
            throw new ValidationException($e->getMessage());
        } catch (\Throwable $e) {
            // Anything unexpected — log it and still return a readable message
            // (super-admin only) rather than a generic 500.
            $this->grav['log']->error('Guard Helper setup failed: ' . $e->getMessage(), ['exception' => $e]);
            throw new ValidationException('Setup failed unexpectedly: ' . $e->getMessage());
        }

        $result['endpoint'] = $this->absoluteEndpoint($result['endpoint_path'] ?? $boot->endpointPath());

        return ApiResponse::create($result);
    }

    /** POST /guard-helper/repair — fresh pairing window on an installed agent. */
    public function repair(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $boot = $this->bootstrap();

        try {
            $result = $boot->repair($this->siteRoot());
            $result['webhook'] = $this->ensureWebhook();
        } catch (BootstrapException $e) {
            throw new ValidationException($e->getMessage());
        } catch (\Throwable $e) {
            $this->grav['log']->error('Guard Helper re-pair failed: ' . $e->getMessage(), ['exception' => $e]);
            throw new ValidationException('Re-pairing failed unexpectedly: ' . $e->getMessage());
        }

        $result['endpoint'] = $this->absoluteEndpoint($result['endpoint_path'] ?? $boot->endpointPath());

        return ApiResponse::create($result);
    }

    /**
     * POST /guard-helper/webhook — switch Grav's scheduler webhook on for the
     * agent, without generating a pairing code. For a site paired before
     * Guard Helper did this on its own.
     */
    public function webhookEnable(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        if (!$this->bootstrap()->isInstalled()) {
            throw new ValidationException('Set up the Guard Agent first.');
        }

        return ApiResponse::create($this->ensureWebhook());
    }

    /**
     * GET /guard-helper/security — the free in-admin checker. Matches this
     * site's installed versions against the public GravSec feed; package list
     * never leaves the box. `?refresh=1` fetches the feed even when a copy is
     * stored ("Check again"). `status` is "unknown" when the feed could not
     * be fetched: nothing was checked, which is not the same as no findings.
     */
    public function security(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $refresh = !empty($request->getQueryParams()['refresh']);
        $result = (new SecurityChecker($this->grav))->run($this->feedUrl(), $refresh);
        $result['cloud_url'] = $this->cloudUrl();

        return ApiResponse::create($result);
    }

    /** This plugin's own version, from its blueprint. */
    private function pluginVersion(): string
    {
        $blueprint = __DIR__ . '/../../blueprints.yaml';
        if (!is_file($blueprint)) {
            return '';
        }
        $src = (string) @file_get_contents($blueprint);

        return preg_match('/^version:\\s*(\\S+)/m', $src, $m) ? trim($m[1], "'\"") : '';
    }

    private function bootstrap(): AgentBootstrap
    {
        return new AgentBootstrap(GRAV_ROOT);
    }

    private function lookupCache(): LookupCache
    {
        return LookupCache::forGrav($this->grav);
    }

    private function webhook(): SchedulerWebhook
    {
        return SchedulerWebhook::forGrav($this->grav, (bool) $this->config->get('plugins.guard-helper.scheduler_webhook', true));
    }

    /** @return array<string,mixed>|null null when it cannot be read; never breaks the page */
    private function webhookStatus(): ?array
    {
        try {
            return $this->webhook()->status();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Switch the scheduler webhook on, reporting rather than throwing: a
     * pairing code that was just generated must still reach the screen.
     *
     * @return array<string,mixed>
     */
    private function ensureWebhook(): array
    {
        try {
            return $this->webhook()->ensure();
        } catch (\Throwable $e) {
            $this->grav['log']->error('Guard Helper could not set up the scheduler webhook: ' . $e->getMessage(), ['exception' => $e]);

            return ['state' => 'error', 'ready' => false, 'changed' => false,
                'message' => "Couldn't set up Grav's scheduler webhook: " . $e->getMessage()];
        }
    }

    private function cloudUrl(): string
    {
        return (string) $this->config->get('plugins.guard-helper.cloud_url', 'https://gravguard.com');
    }

    private function signupUrl(): string
    {
        return (string) $this->config->get('plugins.guard-helper.signup_url', 'https://gravguard.com');
    }

    /** Public GravSec advisory feed URL on the configured cloud. */
    private function feedUrl(): string
    {
        return rtrim($this->cloudUrl(), '/') . '/feed/advisories.json';
    }

    private function absoluteEndpoint(string $path): string
    {
        return $this->siteRoot() . $path;
    }

    private function siteRoot(): string
    {
        return rtrim((string) $this->grav['uri']->rootUrl(true), '/');
    }

    /**
     * Installing the agent provisions a per-site key — reserve it for super
     * admins. (requirePermission already lets supers through, but be explicit:
     * a delegated `api.system.write` grant must NOT unlock agent install.)
     *
     * A bare super-admin check also lets a scoped API key (say `api.pages.read`)
     * minted on a super-admin account through, so the key's scope cap is
     * applied too. The API plugin's requireSuper() does exactly that; older
     * releases without it get the same cap from scopeAllows() or
     * requirePermission(), whichever they have.
     */
    private function requireSuperAdmin(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        if (!$this->isSuperAdmin($user)) {
            throw new ForbiddenException('Setting up the Guard Agent requires a super-admin account.');
        }

        if (method_exists($this, 'requireSuper')) {
            $this->requireSuper($request);
        } elseif (method_exists($this, 'scopeAllows')) {
            if (!$this->scopeAllows($request, 'admin.super')) {
                throw new ForbiddenException('This API key is not authorized for: admin.super');
            }
        } elseif (method_exists($this, 'requirePermission')) {
            $this->requirePermission($request, 'admin.super');
        }
    }
}
