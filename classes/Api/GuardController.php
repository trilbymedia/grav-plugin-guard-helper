<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\BootstrapException;
use Grav\Plugin\GuardHelper\Security\SecurityChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 (API) backend for the browser-only Guard Agent setup.
 *
 * Two endpoints behind the admin-next sidebar page + dashboard widget:
 *   GET  /guard-helper/status — is the agent installed, and where?
 *   POST /guard-helper/setup  — download + verify + unpack + pair in-process
 *
 * Both are super-admin only: installing software and minting a per-site key is
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
        // Only ask the cloud what the latest is when there is something
        // installed to compare it against; a fresh site does not need it.
        $latestAgent = $agentVersion === null ? null : $boot->latestAgentVersion($cloudUrl);

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
            'agent_outdated' => ($agentVersion !== null && $latestAgent !== null)
                ? version_compare($agentVersion, $latestAgent, '<')
                : null,
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
        ]);
    }

    /** POST /guard-helper/setup */
    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $boot = $this->bootstrap();

        try {
            $result = $boot->install($this->cloudUrl());
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
            $result = $boot->repair();
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
     * GET /guard-helper/security — the free in-admin checker. Matches this
     * site's installed versions against the public GravSec feed; package list
     * never leaves the box.
     */
    public function security(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperAdmin($request);

        $result = (new SecurityChecker($this->grav))->run($this->feedUrl());
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
        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        return $root . $path;
    }

    /**
     * Installing the agent provisions a per-site key — reserve it for super
     * admins. (requirePermission already lets supers through, but be explicit:
     * a delegated `api.system.write` grant must NOT unlock agent install.)
     */
    private function requireSuperAdmin(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        if (!$this->isSuperAdmin($user)) {
            throw new ForbiddenException('Setting up the Guard Agent requires a super-admin account.');
        }
    }
}
