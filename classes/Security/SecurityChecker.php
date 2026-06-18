<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

use Grav\Common\Grav;
use Grav\Common\Yaml;

/**
 * The free in-admin security checker (plan §9.1 exposure ladder). Reads the
 * site's own installed package versions, fetches the public GravSec advisory
 * feed, and reports which installed packages have known vulnerabilities — no
 * account, no agent required. This is the funnel: the value is visible before
 * anyone pairs a site.
 *
 * The feed is cached (the public feed changes slowly); matching is local, so a
 * site's package list never leaves the box for the free check.
 */
final class SecurityChecker
{
    private const CACHE_KEY = 'guard-gravsec-feed';
    private const CACHE_TTL = 3600;

    private Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Run the check against the public feed at $feedUrl.
     *
     * @return array{checked_at:string, package_count:int, advisory_count:int, findings:array<int,array<string,mixed>>}
     */
    public function run(string $feedUrl): array
    {
        $installed = $this->installedPackages();
        $advisories = $this->fetchFeed($feedUrl);
        $findings = self::match($installed, $advisories);

        return [
            'checked_at' => gmdate('c'),
            'package_count' => count($installed),
            'advisory_count' => count($advisories),
            'findings' => $findings,
        ];
    }

    /**
     * Match installed packages against advisories. Pure — the testable core.
     *
     * @param array<int,array{type:string, slug:string, version:string}> $installed
     * @param array<int,array<string,mixed>> $advisories
     * @return array<int,array<string,mixed>>
     */
    public static function match(array $installed, array $advisories): array
    {
        $versions = [];
        foreach ($installed as $pkg) {
            $versions[$pkg['type'] . ':' . $pkg['slug']] = (string)$pkg['version'];
        }

        $findings = [];
        foreach ($advisories as $advisory) {
            $key = ($advisory['type'] ?? '') . ':' . ($advisory['slug'] ?? '');
            if (!isset($versions[$key])) {
                continue;
            }
            if (!Constraint::satisfies($versions[$key], (string)($advisory['affected'] ?? ''))) {
                continue;
            }
            $advisory['installed_version'] = $versions[$key];
            $findings[] = $advisory;
        }

        return $findings;
    }

    /**
     * Installed packages read from the filesystem (core + plugin/theme
     * blueprints), matching what the cloud corpus is keyed on.
     *
     * @return array<int,array{type:string, slug:string, version:string}>
     */
    public function installedPackages(): array
    {
        $out = [];
        if (defined('GRAV_VERSION') && GRAV_VERSION !== '') {
            $out[] = ['type' => 'core', 'slug' => 'core', 'version' => (string)GRAV_VERSION];
        }

        $locator = $this->grav['locator'];
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $dir => $type) {
            $base = $locator->findResource('user://' . $dir, true);
            if (!is_string($base) || !is_dir($base)) {
                continue;
            }
            foreach (scandir($base) ?: [] as $slug) {
                if ($slug[0] === '.' || !is_dir("$base/$slug")) {
                    continue;
                }
                $bp = "$base/$slug/blueprints.yaml";
                if (!is_file($bp)) {
                    continue;
                }
                $data = Yaml::parse((string)file_get_contents($bp));
                $version = is_array($data) ? ($data['version'] ?? null) : null;
                if (is_string($version) && $version !== '') {
                    $out[] = ['type' => $type, 'slug' => (string)$slug, 'version' => $version];
                }
            }
        }

        return $out;
    }

    /**
     * Fetch + cache the public advisory feed. Failures (offline, malformed)
     * degrade to an empty feed — the checker just reports nothing, never errors.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchFeed(string $url): array
    {
        $cache = $this->grav['cache'];
        $cached = $cache->fetch(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $advisories = [];
        $raw = $this->httpGet($url);
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['advisories'] ?? null)) {
                $advisories = $decoded['advisories'];
            }
        }

        $cache->save(self::CACHE_KEY, $advisories, self::CACHE_TTL);

        return $advisories;
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 8, 'header' => "User-Agent: GuardHelper\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
